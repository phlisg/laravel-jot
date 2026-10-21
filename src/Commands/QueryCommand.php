<?php

namespace MarvelousDigital\WideEvents\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use MarvelousDigital\WideEvents\Commands\Concerns\ReadsEvents;
use MarvelousDigital\WideEvents\Commands\Table\EventTable;
use MarvelousDigital\WideEvents\Writer;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:query')]
class QueryCommand extends Command
{
    use ReadsEvents;

    protected $signature = 'jot:query
        {id? : A trace ID (32 hex characters) or a span ID (16). Without it, the recent events}
        {--limit=20 : How many recent events to list}
        {--where=* : Field filter, key=value, repeatable; the value may use * wildcards}
        {--errors : Only events with an error.type, a 5xx status or a failed job}
        {--slow : Only events slower than jot.sampling.slow_ms}
        {--since= : Only events newer than this, for example 15m, 2h or 1d}
        {--days= : How many days of log files to read: jot.days with an ID, 1 without}
        {--follow : Keep printing new events as they are written}
        {--json : Print the events as JSON lines}';

    protected $description = 'List the recent wide events in the log files, or find the events of a trace or span';

    public function handle(): int
    {
        $id = $this->argument('id');
        $options = $this->parseOptions($id !== null);

        if (is_int($options)) {
            return $options;
        }

        [$days, $filter] = $options;

        if ($id === null) {
            return $this->list($days, $filter);
        }

        $id = strtolower((string) $id);

        if (! preg_match('/^[0-9a-f]{16}([0-9a-f]{16})?$/', $id)) {
            $this->components->error('Expected a trace ID (32 hex characters) or a span ID (16).');

            return self::INVALID;
        }

        return $this->query($id, $days);
    }

    /**
     * @return array{int, Closure(array<string, mixed>): bool}|int the days of files to read and the event filter, or INVALID
     */
    protected function parseOptions(bool $withId): array|int
    {
        if ($withId) {
            $listOnly = array_filter(
                ['limit', 'where', 'errors', 'slow', 'since', 'follow'],
                fn ($name) => $this->option($name) !== $this->getDefinition()->getOption($name)->getDefault(),
            );

            if ($listOnly !== []) {
                $this->components->error('These options only work without an ID: --'.implode(', --', $listOnly).'.');

                return self::INVALID;
            }

            return [(int) ($this->option('days') ?? config('jot.days', 7)), fn () => true];
        }

        $where = [];

        foreach ((array) $this->option('where') as $filter) {
            if (! str_contains((string) $filter, '=')) {
                $this->components->error("Expected key=value, got: {$filter}");

                return self::INVALID;
            }

            [$key, $value] = explode('=', (string) $filter, 2);
            $where[$key] = $value;
        }

        $days = (int) ($this->option('days') ?? 1);
        $since = null;

        if ($option = $this->option('since')) {
            if (! preg_match('/^(\d+)([smhd])$/', (string) $option, $match)) {
                $this->components->error('Expected --since as a number and a unit, for example 15m, 2h or 1d.');

                return self::INVALID;
            }

            $unit = ['s' => 'seconds', 'm' => 'minutes', 'h' => 'hours', 'd' => 'days'][$match[2]];
            $since = now()->sub($unit, (int) $match[1])->getTimestamp();
            $days = max($days, (int) ceil((now()->getTimestamp() - $since) / 86400));
        }

        return [$days, fn (array $event) => $this->matches($event, $where, $since)];
    }

    protected function query(string $id, int $days): int
    {
        [$files, $elsewhere] = $this->sources($days);
        $span = strlen($id) === 16 ? $this->find($files, $id)->firstWhere('span_id', $id) : null;
        $trace = $span['trace_id'] ?? $id;
        $events = $this->trace($files, $trace);
        $span ??= $events->count() === 1 ? $events->first() : null;

        if ($events->isEmpty()) {
            $this->warnNothing("event with ID {$id}", $days, $files);
        } elseif ($this->option('json')) {
            $events->each(fn ($event) => $this->printEvent($event));
        } elseif (! $span && $events->count() > 1 && $this->atTerminal()) {
            $this->inspect($files, $this->pick($files, $trace));
        } else {
            $this->line("Trace <info>{$trace}</info>, {$events->count()} event(s)");
            foreach ($this->tree($events, $span['span_id'] ?? null) as $line) {
                $this->line($line);
            }

            if ($span) {
                $this->newLine();
                $this->printFields($span);
            } else {
                $this->line('<comment>Run jot:query with a span ID for every field of that event.</comment>');
            }
        }

        $this->printElsewhere($elsewhere, "Also in Sentry Logs: search trace_id:{$trace}.");

        return $events->isEmpty() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  Closure(array<string, mixed>): bool  $filter
     */
    protected function list(int $days, Closure $filter): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $read = function () use ($days, $limit, $filter): array {
            $files = $this->sources($days)[0];

            return [$files, $this->recent($files, $limit, $filter)];
        };
        [$files, $events] = $read();

        if ($events === [] && ! $this->option('follow')) {
            $this->warnNothing('matching event', $days, $files);
        }

        if ($events !== [] && ! $this->option('follow') && ! $this->option('json') && $this->atTerminal()) {
            $this->browse($files, $events, $read);

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $this->printEvent($event);
        }

        if ($events !== [] && ! $this->option('json')) {
            $this->line('<comment>Run jot:query <trace_id> for a trace, or <span_id> for every field.</comment>');
        }

        $this->printElsewhere($this->sources($days)[1], 'Also in Sentry Logs.');

        if ($this->option('follow')) {
            $this->follow($days, $filter);
        }

        return $events === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The last matching events across the files, oldest first.
     *
     * @param  list<string>  $files
     * @param  Closure(array<string, mixed>): bool  $filter
     * @return list<array<string, mixed>>
     */
    protected function recent(array $files, int $limit, Closure $filter): array
    {
        $events = [];

        // Line by line with a bounded buffer per file: a daily file can hold hundreds of megabytes.
        foreach ($files as $file) {
            $buffer = [];

            foreach (new \SplFileObject($file) as $line) {
                if (is_string($line) && ($event = $this->decode($line)) !== null && $filter($event)) {
                    $buffer[] = $event;

                    if (count($buffer) > $limit) {
                        array_shift($buffer);
                    }
                }
            }

            // Keyed by span, so an event written to two outputs prints once.
            foreach ($buffer as $event) {
                $events[$event['span_id']] = $event;
            }
        }

        return collect($events)->sortBy('timestamp')->take(-$limit)->values()->all();
    }

    /**
     * Shows the events in a table, newest first, then every field of the one picked, until Quit.
     *
     * @param  list<string>  $files
     * @param  list<array<string, mixed>>  $events
     * @param  Closure(): array{list<string>, list<array<string, mixed>>}  $read
     */
    protected function browse(array $files, array $events, Closure $read): void
    {
        $events = array_reverse($events);
        $cursor = null;

        while (true) {
            [$index, $refresh] = $this->promptTable($events, $cursor);
            $cursor = $refresh ? ($events[$index]['span_id'] ?? null) : null;

            if ($refresh) {
                [$files, $events] = $read();
                $events = array_reverse($events);

                if ($events === []) {
                    $this->components->warn('No matching event left.');

                    return;
                }
            } elseif ($this->inspect($files, $events[$index], fromList: true) === 'quit') {
                return;
            }
        }
    }

    /**
     * The events table, its search matching the trace and span IDs too, the cursor on the span given.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{int, bool} the row picked, and whether r asked for a refresh instead
     */
    protected function promptTable(array $events, ?string $cursor): array
    {
        $columns = $this->columns($events);
        $rows = array_map(null, ...array_values($columns));

        // The filter gets the row without its key, so the events are looked up by the row's text; identical rows share them.
        $matching = [];
        foreach ($rows as $i => $row) {
            $matching[implode("\0", $row)][] = $events[$i];
        }

        $where = array_map(function ($filter) {
            [$key, $value] = explode('=', (string) $filter, 2);

            return $key.'='.Str::limit($value, 16, '…');
        }, (array) $this->option('where'));

        $table = new EventTable(
            $this->headers($columns),
            $rows,
            scroll: min(10, count($rows)),
            label: implode(' · ', ['Recent events', ...$where]),
            hint: '/ search, or key=value · r refresh · Enter shows every field',
            filter: fn (array $row, string $query) => $this->searched($matching[implode("\0", $row)], $row, $query),
        );
        $table->colors = array_filter(array_map(EventTable::color(...), $events));

        // Arrow presses through the public event API: Prompts keeps highlight() protected.
        $down = array_search($cursor, array_column($events, 'span_id'), true);
        for ($i = 0; $i < (int) $down; $i++) {
            $table->emit('key', Key::DOWN);
        }

        $refresh = false;
        $table->on('key', function (string $key) use ($table, &$refresh) {
            if ($key === 'r' && $table->state !== 'search') {
                $refresh = true;
                $table->state = 'submit';
            }
        });

        return [(int) $table->prompt(), $refresh];
    }

    /**
     * The table's cells by header, without the Session column when one session made every event, or Error when none failed.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, list<string>>
     */
    protected function columns(array $events): array
    {
        $columns = [
            'Time' => array_map($this->time(...), $events),
            'Session' => array_map(fn ($event) => substr((string) ($event['session.hash'] ?? ''), 0, 8), $events),
            'Status' => array_map($this->status(...), $events),
            'What' => array_map($this->what(...), $events),
            'ms' => array_map(fn ($event) => (string) ($event['duration_ms'] ?? ''), $events),
            'Error' => array_map(fn ($event) => Str::limit((string) $this->errorMessage($event), 60), $events),
        ];

        if (count(array_unique($columns['Session'])) < 2) {
            unset($columns['Session']);
        }

        if (array_filter($columns['Error']) === []) {
            unset($columns['Error']);
        }

        return $columns;
    }

    /**
     * The headers, padded to the widest cell (What to 32 at most, Error not at all), so a narrow terminal shrinks Error first.
     * Prompts shrinks every column in proportion and keeps the header's width as the only floor.
     *
     * @param  array<string, list<string>>  $columns
     * @return list<string>
     */
    protected function headers(array $columns): array
    {
        return array_map(
            fn ($header, $cells) => $header === 'Error'
                ? $header
                : str_pad($header, min($header === 'What' ? 32 : PHP_INT_MAX, max(array_map(mb_strwidth(...), [$header, ...$cells])))),
            array_keys($columns),
            $columns,
        );
    }

    /**
     * Whether a search matches the row: key=value as --where on any of the row's events, or else text in the row or their IDs.
     *
     * @param  list<array<string, mixed>>  $events
     * @param  array<int, string>  $row
     */
    protected function searched(array $events, array $row, string $query): bool
    {
        if (str_contains($query, '=')) {
            [$key, $value] = explode('=', $query, 2);

            return collect($events)->contains(fn ($event) => Writer::matches($event, [$key => $value]));
        }

        $text = implode(' ', $row).' '.collect($events)
            ->flatMap(fn ($event) => [$event['trace_id'] ?? '', $event['span_id'], $event['session.hash'] ?? ''])
            ->implode(' ');

        return str_contains(mb_strtolower($text), mb_strtolower($query));
    }

    /**
     * Polls the newest file and prints the matching lines appended to it, until interrupted.
     *
     * @param  Closure(array<string, mixed>): bool  $filter
     */
    protected function follow(int $days, Closure $filter): never
    {
        $file = null;
        $offset = 0;
        $started = false;

        while (true) {
            clearstatcache();
            $newest = collect($this->sources($days)[0])->sortBy(fn ($path) => filemtime($path))->last();

            if ($newest !== $file) {
                // At start, skip what is printed already; a file that appears later, after the daily rollover, is read whole.
                $offset = ! $started && $newest !== null ? (int) filesize($newest) : 0;
                $file = $newest;
            }

            $started = true;

            if ($file !== null && ($handle = @fopen($file, 'r')) !== false) {
                $offset = min($offset, (int) filesize($file));
                fseek($handle, $offset);

                // Only complete lines: the writer may be halfway through the last one.
                while (($line = fgets($handle)) !== false && str_ends_with($line, "\n")) {
                    $offset += strlen($line);

                    if (($event = $this->decode($line)) !== null && $filter($event)) {
                        $this->printEvent($event);
                    }
                }

                fclose($handle);
            }

            $this->pause();
        }
    }

    protected function pause(): void
    {
        usleep(500_000);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, string>  $where
     */
    protected function matches(array $event, array $where, ?int $since): bool
    {
        if (! isset($event['span_id'])
            || ($since !== null && strtotime((string) ($event['timestamp'] ?? '')) < $since)
            || ($this->option('errors') && Writer::level($event) !== LogLevel::ERROR)
            || ($this->option('slow') && ! Writer::slow($event))) {
            return false;
        }

        return Writer::matches($event, $where);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function printEvent(array $event): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($event, JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line($this->time($event).'  '.($event['trace_id'] ?? '?').'  '.$this->summary($event));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function time(array $event): string
    {
        return isset($event['timestamp']) ? Carbon::parse((string) $event['timestamp'])->format('H:i:s') : '--:--:--';
    }
}
