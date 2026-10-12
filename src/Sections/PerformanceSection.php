<?php

namespace MarvelousDigital\WideEvents\Sections;

use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Section;

/**
 * Counters filled by the listeners in Lifecycle. A null counter is switched off.
 */
final class PerformanceSection extends Section
{
    #[Key('memory_peak_mb')]
    public ?float $memoryPeakMb = null;

    #[Key('db.query_count')]
    public ?int $dbQueryCount = null;

    #[Key('db.time_ms')]
    public ?float $dbTimeMs = null;

    #[Key('cache.hits')]
    public ?int $cacheHits = null;

    #[Key('cache.misses')]
    public ?int $cacheMisses = null;

    #[Key('http_client.count')]
    public ?int $httpClientCount = null;

    #[Key('http_client.time_ms')]
    public ?float $httpClientTimeMs = null;

    #[Key('queue.dispatched')]
    public ?int $queueDispatched = null;

    public function __construct()
    {
        $on = fn (string $group) => config("jot.fields.performance.$group", true);

        if ($on('db')) {
            [$this->dbQueryCount, $this->dbTimeMs] = [0, 0.0];
        }

        if ($on('cache')) {
            [$this->cacheHits, $this->cacheMisses] = [0, 0];
        }

        if ($on('http_client')) {
            [$this->httpClientCount, $this->httpClientTimeMs] = [0, 0.0];
        }

        if ($on('queue')) {
            $this->queueDispatched = 0;
        }
    }

    public function finish(): void
    {
        if (config('jot.fields.performance.memory', true)) {
            $this->memoryPeakMb = round(memory_get_peak_usage(true) / 1048576, 1);
        }
    }
}
