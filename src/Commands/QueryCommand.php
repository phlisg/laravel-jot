<?php

namespace MarvelousDigital\WideEvents\Commands;

use Illuminate\Console\Command;
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
                $this->fields($span);
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
}
