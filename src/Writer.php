<?php

namespace MarvelousDigital\WideEvents;

use Closure;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MarvelousDigital\WideEvents\Enums\OutputShape;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Singleton: the sampling rules, user resolver and fake outlive the scoped accumulator.
 */
class Writer
{
    /** @var list<Closure(array<string, mixed>): bool> */
    public array $keeps = [];

    /** @var list<Closure(array<string, mixed>): bool> */
    public array $drops = [];

    public ?Closure $user = null;

    /** @var list<array<string, mixed>>|null null unless faked */
    public ?array $faked = null;

    protected ?LoggerInterface $defaultChannel = null;

    public function write(array $event): void
    {
        if (! $this->keep($event)) {
            return;
        }

        if ($this->faked !== null) {
            $this->faked[] = $event;

            return;
        }

        $level = static::level($event);

        // Logging must never break the app.
        rescue(function () use ($event, $level) {
            foreach ($this->outputs() as $output) {
                if (isset($output['level']) && Level::fromName($level)->isLowerThan(Level::fromName($output['level']))) {
                    continue;
                }

                $shape = $output['shape'] ?? OutputShape::Flat;
                $shape = $shape instanceof OutputShape ? $shape : OutputShape::from($shape);

                $this->channel($output['channel'] ?? null)->log($level, 'jot', $shape->apply($event));
            }
        });
    }

    /**
     * The log level of an event, so each channel can keep only what it needs.
     */
    public static function level(array $event): string
    {
        $status = $event['http.response.status_code'] ?? 0;

        $derived = match (true) {
            isset($event['error.type']), $status >= 500, ($event['job.status'] ?? null) === 'failed' => LogLevel::ERROR,
            $status >= 400, ! in_array($event['console.exit_code'] ?? $event['schedule.exit_code'] ?? 0, [0, null], true) => LogLevel::WARNING,
            static::slow($event) => LogLevel::NOTICE,
            default => LogLevel::INFO,
        };

        $raised = $event['level.raised'] ?? null;

        return $raised && Level::fromName($raised)->isHigherThan(Level::fromName($derived)) ? $raised : $derived;
    }

    public static function slow(array $event): bool
    {
        return ($event['duration_ms'] ?? 0) > config('jot.sampling.slow_ms', 1000);
    }

    public static function defaultPath(): string
    {
        return storage_path('logs/jot.log');
    }

    /**
     * The configured outputs, plus Sentry Logs when the Sentry SDK has logs enabled.
     *
     * @return list<array{channel?: ?string, shape?: OutputShape|string, level?: string}>
     */
    public function outputs(): array
    {
        // An empty JOT_CHANNEL= in .env reads as "", which Log::channel() takes for the app's default channel.
        $outputs = array_map(fn (array $output) => ['channel' => ($output['channel'] ?? null) ?: null] + $output, config('jot.outputs', []));

        $sentry = config('jot.sentry.enabled', true)
            && app()->bound('sentry')
            && config('sentry.enable_logs')
            && ! in_array('sentry_logs', array_column($outputs, 'channel'), true);

        if ($sentry) {
            $outputs[] = ['channel' => 'sentry_logs', 'shape' => OutputShape::Flat, 'level' => config('jot.sentry.level', LogLevel::INFO)];
        }

        return $outputs;
    }

    /**
     * Tail sampling, see "Sampling" in the README.
     */
    public function keep(array $event): bool
    {
        $level = static::level($event);
        $status = $event['http.response.status_code'] ?? 0;

        if ($level === LogLevel::ERROR || static::slow($event) || isset($event['level.raised']) || ($level === LogLevel::WARNING && $status < 400)) {
            return $this->underLimit($event, $level);
        }

        if ($this->dropped($event)) {
            return false;
        }

        foreach ($this->keeps as $rule) {
            if ($rule($event)) {
                return true;
            }
        }

        $rate = (float) config($status >= 400 ? 'jot.sampling.client_errors' : 'jot.sampling.rate', 1.0);

        return $rate >= 1 || mt_rand() / mt_getrandmax() < $rate;
    }

    /**
     * Every key matches its pattern: * wildcards, commas for OR. The syntax of jot:query --where.
     *
     * @param  array<string, mixed>  $where
     */
    public static function matches(array $event, array $where): bool
    {
        $text = fn (mixed $value) => is_string($value) ? $value : (string) json_encode($value);

        foreach ($where as $key => $pattern) {
            $value = $event[$key] ?? null;

            if ($value === null || ! Str::is(explode(',', $text($pattern)), $text($value))) {
                return false;
            }
        }

        return true;
    }

    protected function dropped(array $event): bool
    {
        if (config('jot.sampling.drop_livewire_polls', true) && static::livewirePoll($event)) {
            return true;
        }

        foreach (config('jot.sampling.drop', []) as $where) {
            if ($where && static::matches($event, $where)) {
                return true;
            }
        }

        foreach ($this->drops as $rule) {
            if ($rule($event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An update with no call, wire:model change or event. Needs the Livewire fields to tell.
     */
    protected static function livewirePoll(array $event): bool
    {
        return isset($event['livewire.components'])
            && (($event['livewire.poll'] ?? false)
                || ! isset($event['livewire.calls']) && ! isset($event['livewire.updates']) && ! isset($event['livewire.events']));
    }

    /**
     * Caps an error storm: a database outage turns every request into an error.
     */
    protected function underLimit(array $event, string $level): bool
    {
        $limit = config('jot.sampling.max_per_minute', 10);

        if (! $limit) {
            return true;
        }

        $kind = implode('|', [$level, $event['error.type'] ?? '', $event['http.route'] ?? $event['job.name'] ?? $event['console.command'] ?? $event['schedule.task'] ?? '']);
        $key = 'jot:limit:'.md5($kind).':'.now()->format('YmdHi');

        // A cache store that fails keeps the event. ponytail: file and database stores count without a lock, so the cap is approximate under load.
        return rescue(function () use ($key, $limit) {
            Cache::add($key, 0, 120);

            return Cache::increment($key) <= (int) $limit;
        }, true, false);
    }

    protected function channel(?string $channel): LoggerInterface
    {
        if ($channel !== null) {
            return Log::channel($channel);
        }

        // Built by hand: Laravel's channels append Context to "extra", which repeats the event's IDs.
        // The Laravel wrapper still fires MessageLogged, which Pail listens to.
        return $this->defaultChannel ??= new Logger(new Monolog('jot', [
            (new RotatingFileHandler(static::defaultPath(), (int) config('jot.days', 7)))->setFormatter(new JsonFormatter(ignoreEmptyContextAndExtra: true)),
        ]), app('events'));
    }
}
