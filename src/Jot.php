<?php

namespace Phlisg\Jot;

use BackedEnum;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Phlisg\Jot\Exceptions\JotSectionRequiresInitialisation;
use Phlisg\Jot\Sections\AppSection;
use Phlisg\Jot\Sections\ClientSection;
use Phlisg\Jot\Sections\HttpSection;
use Phlisg\Jot\Sections\QuerySection;
use Phlisg\Jot\Sections\RequestSection;
use ReflectionClass;
use ReflectionException;

class Jot
{
    use Utils;

    protected array $sections = [];

    protected array $fields = []; // The resolved fields with their values, flattened at emit-time

    protected array $owners = []; // field => 'string', 'enum' or a section class

    protected array $reserved = []; // Built-in sections.

    /**
     * Builds the sections known when a request starts, one per group switched
     * on in config/jot.php. The end-of-request groups come at terminate.
     */
    public function initialize(Request $request): static
    {
        $this->reserved = array_values(array_filter([
            new RequestSection($request),
            config('jot.fields.http', true) ? new HttpSection($request) : null,
            config('jot.fields.client') ? new ClientSection($request) : null,
            config('jot.fields.query.allow') ? new QuerySection($request) : null,
            config('jot.fields.app', true) ? new AppSection : null,
        ]));

        return $this;
    }

    /**
     * The final flat event. Pure: safe to call any number of times.
     */
    public function event(): array
    {
        return $this->build()[0];
    }

    /**
     * Builds the event and logs its collisions. Writing to the outputs comes next.
     */
    public function emit(): array
    {
        [$event, $collisions] = $this->build();

        foreach ($collisions as [$field, $first, $then]) {
            logger()->warning(sprintf(
                '[jot] Key "%s" was already added by %s, then overwritten by %s.',
                $field,
                $first,
                $then,
            ));
        }

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
