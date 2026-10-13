<?php

namespace MarvelousDigital\WideEvents\Commands\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use MarvelousDigital\WideEvents\Writer;

/**
 * Reads the events back from the log files, for a command with a --days option.
 */
trait ReadsEvents
{
    /**
     * The files of the outputs that write to disk, and the channels of the others.
     *
     * @return array{list<string>, list<string>}
     */
    protected function sources(): array
    {
        $files = [];
        $elsewhere = [];
        $since = now()->subDays((int) $this->option('days'))->getTimestamp();

        foreach (app(Writer::class)->outputs() as $output) {
            $channel = $output['channel'] ?? null;
            $config = $channel === null ? ['driver' => 'daily', 'path' => storage_path('logs/jot.log')] : config("logging.channels.{$channel}");

            if (! in_array($config['driver'] ?? null, ['single', 'daily'], true)) {
                $elsewhere[] = $channel;

                continue;
            }

            $path = $config['path'];
            $candidates = $config['driver'] === 'daily'
                ? glob(dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].'.pathinfo($path, PATHINFO_EXTENSION)) ?: []
                : [$path];

            foreach ($candidates as $file) {
                if (is_file($file) && filemtime($file) >= $since) {
                    $files[] = $file;
                }
            }
        }

        return [array_values(array_unique($files)), $elsewhere];
    }

    /**
     * Reads a line written by Monolog's JsonFormatter or LineFormatter, in any output shape.
     *
     * @return array<string, mixed>|null
     */
    protected function decode(string $line): ?array
    {
        $payload = json_decode($line, true);

        // LineFormatter: "... jot {context} {extra}". Laravel puts its Context in extra, so try each closing brace from the end.
        if (! is_array($payload) && ($start = strpos($line, ' jot {')) !== false) {
            for ($end = strrpos($line, '}'); $end > $start && ! is_array($payload); $end = strrpos(substr($line, 0, $end), '}')) {
                $payload = json_decode(substr($line, $start + 5, $end - $start - 4), true);
            }

            $payload = is_array($payload) ? ['context' => $payload] : null;
        }

        $event = $payload['context'] ?? null;

        if (! is_array($event)) {
            return null;
        }

        if (isset($event['TraceId'])) {
            $event = ['timestamp' => $event['Timestamp'] ?? null, 'trace_id' => $event['TraceId'], 'span_id' => $event['SpanId'] ?? null]
                + ($event['Attributes'] ?? []) + ($event['Resource'] ?? []);
        }

        return Arr::dot($event);
    }

    /**
     * The events on the lines that contain the needle, flattened, one per span.
     *
     * @param  list<string>  $files
     * @return Collection<array-key, array<string, mixed>>
     */
    protected function find(array $files, string $needle): Collection
    {
        /** @var array<array-key, array<string, mixed>> $events */
        $events = [];

        // Line by line: a daily file can hold hundreds of megabytes.
        foreach ($files as $file) {
            foreach (new \SplFileObject($file) as $line) {
                if (is_string($line) && str_contains($line, $needle) && isset(($event = $this->decode($line))['span_id'])) {
                    $events[$event['span_id']] = $event;
                }
            }
        }

        return collect($events);
    }

    /**
     * The trace as indented lines with branch characters, keyed by span ID, the marked span pointed at.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<array-key, string>
     */
    protected function tree(Collection $events, ?string $marked, ?string $parent = null, string $indent = ''): array
    {
        $spans = $events->pluck('span_id')->all();
        $children = $events->filter(fn ($event) => $parent === null
            ? ! in_array($event['parent_span_id'] ?? null, $spans, true)
            : ($event['parent_span_id'] ?? null) === $parent);
        $lines = [];

        foreach ($children->values() as $i => $event) {
            $last = $i === $children->count() - 1;
            $branch = $parent === null ? '' : ($last ? '└─ ' : '├─ ');
            $mark = $event['span_id'] === $marked ? ' <info>◀</info>' : '';

            $lines[$event['span_id']] = $indent.$branch.$this->summary($event).$mark;
            $lines += $this->tree($events, $marked, $event['span_id'], $parent === null ? '' : $indent.($last ? '   ' : '│  '));
        }

        return $lines;
    }

    /**
     * Prints every field of one event.
     *
     * @param  array<string, mixed>  $event
     */
    protected function fields(array $event): void
    {
        $this->components->twoColumnDetail('<info>'.$event['span_id'].'</info>');
        collect(Arr::dot($event))->each(fn ($value, $key) => $this->components->twoColumnDetail($key, is_string($value) ? $value : json_encode($value)));
    }

    /**
     * The method, route and status of a request, the job and its status, or the command and its exit code.
     *
     * @param  array<string, mixed>  $event
     */
    protected function what(array $event): string
    {
        $what = match ($event['entrypoint'] ?? null) {
            'http' => [$event['http.request.method'] ?? null, $event['http.route'] ?? $event['url.path'] ?? null, $event['http.response.status_code'] ?? null],
            'queue' => [class_basename((string) ($event['job.name'] ?? '')), $event['job.status'] ?? null],
            'console' => [$event['console.command'] ?? null, isset($event['console.exit_code']) ? "exit {$event['console.exit_code']}" : null],
            'schedule' => [$event['schedule.task'] ?? null, isset($event['schedule.exit_code']) ? "exit {$event['schedule.exit_code']}" : null],
            default => [],
        };

        return implode(' ', array_filter($what, fn ($part) => $part !== null && $part !== ''));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function errorMessage(array $event): ?string
    {
        return isset($event['error.type']) ? class_basename((string) $event['error.type']).': '.($event['exception.message'] ?? '') : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function summary(array $event): string
    {
        $error = $this->errorMessage($event);

        return implode('  ', array_filter([
            '<comment>'.($event['entrypoint'] ?? '?').'</comment>',
            $event['span_id'],
            $this->what($event),
            isset($event['duration_ms']) ? "{$event['duration_ms']}ms" : null,
            $error === null ? null : "<error>{$error}</error>",
        ]));
    }
}
