<?php

namespace Phlisg\Jot;

use Closure;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Phlisg\Jot\Enums\OutputShape;
use Psr\Log\LoggerInterface;

/**
 * Singleton: the sampling rules, user resolver and fake outlive the scoped accumulator.
 */
class Writer
{
    /** @var list<Closure(array<string, mixed>): bool> */
    public array $rules = [];

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

        // Logging must never break the app.
        rescue(function () use ($event) {
            foreach (config('jot.outputs', []) as $output) {
                $shape = $output['shape'] ?? OutputShape::Flat;
                $shape = $shape instanceof OutputShape ? $shape : OutputShape::from($shape);

                $this->channel($output['channel'] ?? null)->info('jot', $shape->apply($event));
            }
        });
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

        return $this->defaultChannel ??= Log::build([
            'driver' => 'daily',
            'path' => storage_path('logs/jot.log'),
            'formatter' => JsonFormatter::class,
        ]);
    }
}
