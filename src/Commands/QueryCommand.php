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

        $days = (int) $this->option('days');
        [$files, $elsewhere] = $this->sources($days);
        $span = strlen($id) === 16 ? $this->find($files, $id)->firstWhere('span_id', $id) : null;
        $trace = $span['trace_id'] ?? $id;
        $events = $this->trace($files, $trace);

        if ($events->isEmpty()) {
            $this->warnNothing("event with ID {$id}", $days, $files);
        } elseif ($this->option('json')) {
            $events->each(fn ($event) => $this->line(json_encode($event, JSON_UNESCAPED_SLASHES)));
        } elseif (! $span && $events->count() > 1 && $this->atTerminal()) {
            $this->inspect($files, $this->pick($files, $trace), ['trace' => 'Back to the trace', 'quit' => 'Quit']);
        } else {
            $this->line("Trace <info>{$trace}</info>, {$events->count()} event(s)");
            foreach ($this->tree($events, $span['span_id'] ?? null) as $line) {
                $this->line($line);
            }

            if ($span) {
                $this->newLine();
                $this->fields($span);
            } else {
                $this->line('<comment>Run jot:query with a span ID for every field of that event.</comment>');
            }
        }

        $this->printElsewhere($elsewhere, "Also in Sentry Logs: search trace_id:{$trace}.");

        return $events->isEmpty() ? self::FAILURE : self::SUCCESS;
    }
}
