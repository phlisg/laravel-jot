<?php

namespace MarvelousDigital\WideEvents\Commands\Concerns;

use Illuminate\Support\Arr;
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
     * @param  array<string, mixed>  $event
     */
    protected function summary(array $event): string
    {
        $what = match ($event['entrypoint'] ?? null) {
            'http' => [$event['http.request.method'] ?? null, $event['http.route'] ?? $event['url.path'] ?? null, $event['http.response.status_code'] ?? null],
            'queue' => [class_basename((string) ($event['job.name'] ?? '')), $event['job.status'] ?? null],
            'console' => [$event['console.command'] ?? null, isset($event['console.exit_code']) ? "exit {$event['console.exit_code']}" : null],
            'schedule' => [$event['schedule.task'] ?? null, isset($event['schedule.exit_code']) ? "exit {$event['schedule.exit_code']}" : null],
            default => [],
        };

        $error = isset($event['error.type']) ? '<error>'.class_basename((string) $event['error.type']).': '.($event['exception.message'] ?? '').'</error>' : null;

        return implode('  ', array_filter([
            '<comment>'.($event['entrypoint'] ?? '?').'</comment>',
            $event['span_id'],
            implode(' ', array_filter($what, fn ($part) => $part !== null && $part !== '')),
            isset($event['duration_ms']) ? "{$event['duration_ms']}ms" : null,
            $error,
        ]));
    }
}
