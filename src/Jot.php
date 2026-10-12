<?php

namespace MarvelousDigital\WideEvents;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use MarvelousDigital\WideEvents\Exceptions\JotSectionRequiresInitialisation;
use MarvelousDigital\WideEvents\Sections\AppSection;
use MarvelousDigital\WideEvents\Sections\ClientSection;
use MarvelousDigital\WideEvents\Sections\ErrorSection;
use MarvelousDigital\WideEvents\Sections\HttpSection;
use MarvelousDigital\WideEvents\Sections\PerformanceSection;
use MarvelousDigital\WideEvents\Sections\QuerySection;
use MarvelousDigital\WideEvents\Sections\RequestSection;
use MarvelousDigital\WideEvents\Sections\UserSection;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Jot
{
    use Utils;

    protected array $sections = [];

    protected array $fields = []; // The resolved fields with their values, flattened at emit-time

    protected array $owners = []; // field => 'string', 'enum' or a section class

    /** @var array<class-string<Section>, Section> */
    protected array $reserved = []; // Built-in sections, keyed by class.

    protected ?int $startedAt = null; // hrtime, in nanoseconds

    /**
     * Starts an HTTP event: the sections known when a request starts, one per
     * group switched on in config/jot.php. The end-of-request fields come in finalize().
     */
    public function initialize(Request $request): static
    {
        $user = config('jot.fields.user', true) || config('jot.fields.session', true)
            || config('jot.fields.auth', true) || config('jot.fields.locale', true);

        return $this->start(
            RequestSection::fromRequest($request),
            config('jot.fields.http', true) ? new HttpSection($request) : null,
            config('jot.fields.client') ? new ClientSection($request) : null,
            config('jot.fields.query.allow') ? new QuerySection($request) : null,
            $user ? new UserSection : null,
        );
    }

    /**
     * Starts any event. Only the IDs go to Context, see "Context" in docs/architecture.md.
     */
    public function start(RequestSection $ids, ?Section ...$sections): static
    {
        $sections = [
            $ids,
            ...$sections,
            config('jot.fields.app', true) ? new AppSection : null,
            config('jot.fields.performance') ? new PerformanceSection : null,
        ];

        $this->reserved = [];

        foreach (array_filter($sections) as $section) {
            $this->reserved[$section::class] = $section;
        }

        $this->startedAt = hrtime(true);

        $tags = array_filter(['request_id' => $ids->id, 'trace_id' => $ids->traceId]);

        Context::add($tags + ['origin' => $ids->origin]);

        // Links each Sentry issue to its wide event.
        if (config('jot.sentry.enabled', true) && app()->bound('sentry')) {
            \Sentry\configureScope(fn ($scope) => $scope->setTags($tags));
        }

        return $this;
    }

    public function finalize(Request $request, Response $response): static
    {
        $this->builtIn(HttpSection::class)?->finish($request, $response);
        $this->builtIn(UserSection::class)?->finish($request);

        $resolver = app(Writer::class)->user;

        if ($resolver && config('jot.fields.user', true) && $user = $request->user()) {
            foreach ($resolver($user) as $field => $value) {
                $this->appendKey($field, $value, 'Jot::user()');
            }
        }

        return $this;
    }

    /**
     * @template T of Section
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function builtIn(string $class): ?Section
    {
        return $this->reserved[$class] ?? null;
    }

    public function requestId(): ?string
    {
        return $this->builtIn(RequestSection::class)?->id;
    }

    public function error(Throwable $e): static
    {
        if (config('jot.fields.errors', true)) {
            $this->reserved[ErrorSection::class] = new ErrorSection($e);
        }

        return $this;
    }

    /**
     * Fields the app derives from the authenticated user, added at the end of each request.
     *
     * @param  Closure(Authenticatable): array<string, mixed>  $resolver
     */
    public function user(Closure $resolver): void
    {
        app(Writer::class)->user = $resolver;
    }

    /**
     * A sampling rule: an event it returns true for is always written.
     *
     * @param  Closure(array<string, mixed>): bool  $rule
     */
    public function keepWhen(Closure $rule): void
    {
        app(Writer::class)->rules[] = $rule;
    }

    public function fake(): void
    {
        app(Writer::class)->faked = [];
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $matches
     */
    public function assertEmitted(?Closure $matches = null): void
    {
        $emitted = app(Writer::class)->faked ?? [];

        Assert::assertNotEmpty(
            $matches ? array_filter($emitted, $matches) : $emitted,
            'No jot event was emitted'.($matches ? ' that matches the callback.' : '.'),
        );
    }

    /**
     * The final flat event. Pure: safe to call any number of times.
     */
    public function event(): array
    {
        return $this->build()[0];
    }

    /**
     * Builds the event, logs its collisions and writes it to the outputs.
     * An event that never started writes nothing.
     */
    public function emit(): array
    {
        $ids = $this->builtIn(RequestSection::class);

        if (! $ids) {
            return [];
        }

        $ids->durationMs = (int) round((hrtime(true) - $this->startedAt) / 1e6);
        $this->builtIn(PerformanceSection::class)?->finish();

        [$event, $collisions] = $this->build();

        foreach ($collisions as [$field, $first, $then]) {
            logger()->warning(sprintf(
                '[jot] Key "%s" was already added by %s, then overwritten by %s.',
                $field,
                $first,
                $then,
            ));
        }

        app(Writer::class)->write($event);

        return $event;
    }

    /**
     * "Cute" alias to `emit()`
     * */
    public function down(): array
    {
        return $this->emit();
    }

    final public function getSection(Section|string $section): ?Section
    {
        if (is_subclass_of($section, Section::class)) {
            $this->appendSection($section);
        }

        return $this->sections[$section::prefix()];
    }

    public function add(string|array|BackedEnum|Section $key, mixed $value = null): static
    {
        if ($key instanceof Section) {
            $this->appendSection($key);

            return $this;
        }

        foreach ($this->pairs($key, $value) as [$field, $fieldValue, $owner]) {
            $this->appendKey($field, $fieldValue, $owner);
        }

        return $this;
    }

    public function addIfMissing(string|array|BackedEnum|Section $key, mixed $value = null): static
    {
        if ($key instanceof Section) {
            if (! isset($this->sections[$key::prefix()])) {
                $this->appendSection($key);
            }

            return $this;
        }

        foreach ($this->pairs($key, $value) as [$field, $fieldValue, $owner]) {
            if (! array_key_exists($field, $this->fields)) {
                $this->appendKey($field, $fieldValue, $owner);
            }
        }

        return $this;
    }

    public function increment(string|array|BackedEnum $key, int $by = 1): static
    {
        foreach ($this->pairs($key, 0) as [$field, $start, $owner]) {
            $current = $this->fields[$field] ?? $start;

            // A non-numeric field is left alone: logging never breaks the app.
            if (is_numeric($current)) {
                $this->appendKey($field, $current + $by, $owner);
            }
        }

        return $this;
    }

    public function push(string|array|BackedEnum $key, mixed $value): static
    {
        foreach ($this->pairs($key, $value) as [$field, $pushed, $owner]) {
            $current = $this->fields[$field] ?? [];

            if (is_array($current)) {
                $current[] = $pushed;
                $this->appendKey($field, $current, $owner);
            }
        }

        return $this;
    }

    /**
     * Every key form as [field, value, owner] triples. A list of keys, from
     * increment() and push(), shares the same value.
     *
     * @return list<array{string, mixed, string}>
     */
    protected function pairs(string|array|BackedEnum $key, mixed $value): array
    {
        if (! is_array($key)) {
            return [$this->pair($key, $value)];
        }

        if (array_is_list($key)) {
            return array_map(fn ($listed) => $this->pair($listed, $value), $key);
        }

        $pairs = [];

        foreach ($key as $field => $fieldValue) {
            $pairs[] = $this->pair($field, $fieldValue);
        }

        return $pairs;
    }

    /**
     * @return array{string, mixed, string}
     */
    protected function pair(string|int|BackedEnum $key, mixed $value): array
    {
        $field = $this->fieldName($key);
        $owner = $this->ownerOf($key);

        if ($owner === 'string' && config('jot.strict', false)) {
            logger()->warning(sprintf('[jot] String key "%s" written; declare it in an enum or a section.', $field));
        }

        return [$field, $value, $owner];
    }

    protected function fieldName(string|int|BackedEnum $key): string
    {
        return (string) ($key instanceof BackedEnum ? $key->value : $key);
    }

    protected function ownerOf(string|int|BackedEnum $key): string
    {
        return $key instanceof BackedEnum ? 'enum' : 'string';
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function appendSection(Section|string $section): void
    {
        if (! is_subclass_of($section, Section::class)) {
            throw new InvalidArgumentException('Section must be a subclass of '.Section::class);
        }

        if (! is_string($section)) {
            $this->sections[$section::prefix()] = $section;

            return;
        }

        if (isset($this->sections[$section::prefix()])) {
            return;
        }

        if ((new ReflectionClass($section))->getConstructor()?->getNumberOfRequiredParameters()) {
            throw JotSectionRequiresInitialisation::for($section);
        }

        $this->sections[$section::prefix()] = new $section;
    }

    protected function appendKey(string $field, mixed $value, string $owner): void
    {
        // Sections have no keyed form: the prefix comes from the class.
        if ($value instanceof Section) {
            if ($field !== $value::prefix()) {
                logger()->warning(sprintf(
                    '[jot] Section %s was added under key "%s"; it writes under its own prefix "%s".',
                    $value::class,
                    $field,
                    $value::prefix(),
                ));
            }

            $this->appendSection($value);

            return;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        $previous = $this->owners[$field] ?? null;

        // See "Key collisions" in the README. Section keys get their owner at emit.
        if ($previous !== null && $previous !== $owner) {
            logger()->warning(sprintf(
                '[jot] Key "%s" was already added by %s, then overwritten by %s.',
                $field,
                $previous,
                $owner,
            ));
        }

        $this->fields[$field] = $value;
        $this->owners[$field] = $owner;
    }

    /**
     * Fields first, then each section flattened on top, then the reserved keys.
     * A section key that lands on a field is a collision: the section wins.
     *
     * @return array{array<string, mixed>, list<array{string, string, string}>}
     */
    protected function build(): array
    {
        $reserved = [];

        foreach ($this->reserved as $section) {
            $reserved = array_merge($reserved, $section->toDotNotation());
        }

        $event = $this->fields;
        $collisions = [];

        foreach ($this->sections as $section) {
            foreach ($section->toDotNotation() as $field => $value) {
                if (array_key_exists($field, $event)) {
                    $collisions[] = [$field, $this->owners[$field] ?? 'a field', $section::class];
                }

                $event[$field] = $value;
            }
        }

        foreach (array_keys(array_intersect_key($event, $reserved)) as $field) {
            $collisions[] = [$field, $this->owners[$field] ?? 'a section', 'Jot'];
        }

        // A value and child keys under one name: nested output cannot hold both, so the value goes.
        foreach ($this->parents(array_keys($reserved + $event)) as $parent) {
            if (array_key_exists($parent, $event)) {
                $collisions[] = [$parent, $this->owners[$parent] ?? 'a field', 'its child keys'];
                unset($event[$parent]);
            }
        }

        // `+` puts the reserved keys first and keeps their values on a duplicate key.
        return [$this->grouped($reserved) + $this->grouped($event), $collisions];
    }

    /**
     * Keys sharing a first segment side by side, each group where its first key
     * appeared: payment.a, booking.x, payment.b gives payment.a, payment.b, booking.x.
     */
    protected function grouped(array $event): array
    {
        $groups = [];

        foreach ($event as $key => $value) {
            $groups[explode('.', (string) $key)[0]][$key] = $value;
        }

        $ordered = [];

        foreach ($groups as $group) {
            $ordered += $group;
        }

        return $ordered;
    }

    /**
     * Every ancestor of the given dotted keys: payment.card.last4 gives payment and payment.card.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function parents(array $keys): array
    {
        $parents = [];

        foreach ($keys as $key) {
            $segments = explode('.', $key);

            while (count($segments) > 1) {
                array_pop($segments);
                $parents[implode('.', $segments)] = true;
            }
        }

        return array_keys($parents);
    }
}
