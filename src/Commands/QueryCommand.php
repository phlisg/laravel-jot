<?php

namespace MarvelousDigital\WideEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use MarvelousDigital\WideEvents\Commands\Concerns\ReadsEvents;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:query')]
class QueryCommand extends Command
{
    use ReadsEvents;

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
}
