<?php

namespace MarvelousDigital\WideEvents;

use Closure;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
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
    public array $rules = [];

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

        return match (true) {
            isset($event['error.type']), $status >= 500, ($event['job.status'] ?? null) === 'failed' => LogLevel::ERROR,
            $status >= 400, ! in_array($event['console.exit_code'] ?? $event['schedule.exit_code'] ?? 0, [0, null], true) => LogLevel::WARNING,
            ($event['duration_ms'] ?? 0) > config('jot.sampling.slow_ms', 1000) => LogLevel::NOTICE,
            default => LogLevel::INFO,
        };
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
        if (($event['http.response.status_code'] ?? 0) >= 500
            || ($event['job.status'] ?? null) === 'failed'
            || isset($event['error.type'])
            || ($event['duration_ms'] ?? 0) > config('jot.sampling.slow_ms', 1000)) {
            return true;
        }

        foreach ($this->drops as $rule) {
            if ($rule($event)) {
                return false;
            }
        }

        foreach ($this->rules as $rule) {
            if ($rule($event)) {
                return true;
            }
        }

        $rate = (float) config('jot.sampling.rate', 1.0);

        return $rate >= 1 || mt_rand() / mt_getrandmax() < $rate;
    }

    protected function channel(?string $channel): LoggerInterface
    {
        if ($channel !== null) {
            return Log::channel($channel);
        }

        // Built by hand: Laravel's channels append Context to "extra", which repeats the event's IDs.
        // The Laravel wrapper still fires MessageLogged, which Pail listens to.
        return $this->defaultChannel ??= new Logger(new Monolog('jot', [
            (new RotatingFileHandler(storage_path('logs/jot.log'), 7))->setFormatter(new JsonFormatter(ignoreEmptyContextAndExtra: true)),
        ]), app('events'));
    }
}
