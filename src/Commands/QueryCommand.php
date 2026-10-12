<?php

namespace MarvelousDigital\WideEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use MarvelousDigital\WideEvents\Writer;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:query')]
class QueryCommand extends Command
{
    protected $signature = 'jot:query
        {id : A trace ID (32 hex characters) or a span ID (16)}
        {--days=7 : How many days of log files to read}
        {--json : Print the events as JSON lines}';

    protected $description = 'Find the wide events of a trace, or of one span, in the log files';

    public function handle(): int
    {
        $id = strtolower((string) $this->argument('id'));

        if (! preg_match('/^[0-9a-f]{16}([0-9a-f]{16})?$/', $id)) {
            $this->components->error('Expected a trace ID (32 hex characters) or a span ID (16).');

            return self::INVALID;
        }

        [$files, $elsewhere] = $this->sources();
        $span = strlen($id) === 16 ? $this->find($files, $id)->firstWhere('span_id', $id) : null;
        $trace = $span['trace_id'] ?? $id;
        $events = $this->find($files, $trace)->where('trace_id', $trace)->sortBy('timestamp')->values();

        if ($events->isEmpty()) {
            $this->components->warn("No event with ID {$id} in the last {$this->option('days')} days of: ".($files ? implode(', ', $files) : 'no readable log file'));
        } elseif ($this->option('json')) {
            $events->each(fn ($event) => $this->line(json_encode($event, JSON_UNESCAPED_SLASHES)));
        } else {
            $this->line("Trace <info>{$trace}</info>, {$events->count()} event(s)");
            $this->tree($events, null, '', $span['span_id'] ?? null);

            if ($span) {
                $this->newLine();
                $this->components->twoColumnDetail('<info>'.$span['span_id'].'</info>');
                collect(Arr::dot($span))->each(fn ($value, $key) => $this->components->twoColumnDetail($key, is_string($value) ? $value : json_encode($value)));
            } else {
                $this->line('<comment>Run jot:query with a span ID for every field of that event.</comment>');
            }
        }

        foreach ($elsewhere as $channel) {
            $this->line($channel === 'sentry_logs'
                ? "<comment>Also in Sentry Logs: search trace_id:{$trace}.</comment>"
                : "<comment>Also written to the {$channel} channel, which jot:query cannot read.</comment>");
        }

        return $events->isEmpty() ? self::FAILURE : self::SUCCESS;
    }

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
     * @param  Collection<int, array<string, mixed>>  $events
     */
    protected function tree(Collection $events, ?string $parent, string $indent, ?string $marked): void
    {
        $spans = $events->pluck('span_id')->all();
        $children = $events->filter(fn ($event) => $parent === null
            ? ! in_array($event['parent_span_id'] ?? null, $spans, true)
            : ($event['parent_span_id'] ?? null) === $parent);

        foreach ($children->values() as $i => $event) {
            $last = $i === $children->count() - 1;
            $branch = $parent === null ? '' : ($last ? '└─ ' : '├─ ');
            $mark = $event['span_id'] === $marked ? ' <info>◀</info>' : '';

            $this->line($indent.$branch.$this->summary($event).$mark);
            $this->tree($events, $event['span_id'], $parent === null ? '' : $indent.($last ? '   ' : '│  '), $marked);
        }
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
