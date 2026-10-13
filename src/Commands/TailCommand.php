<?php

namespace MarvelousDigital\WideEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use MarvelousDigital\WideEvents\Commands\Concerns\BrowsesTraces;
use MarvelousDigital\WideEvents\Commands\Concerns\ReadsEvents;
use MarvelousDigital\WideEvents\Writer;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:tail')]
class TailCommand extends Command
{
    use BrowsesTraces;
    use ReadsEvents;

    protected $signature = 'jot:tail
        {--limit=20 : How many events to print}
        {--where=* : Field filter, key=value, repeatable; the value may use * wildcards}
        {--errors : Only events with an error.type, a 5xx status or a failed job}
        {--slow : Only events slower than jot.sampling.slow_ms}
        {--since= : Only events newer than this, for example 15m, 2h or 1d}
        {--days=1 : How many days of log files to read}
        {--follow : Keep printing new events as they are written}
        {--json : Print the events as JSON lines}
        {--i|interactive : Browse the events in a table, Enter shows every field}';

    protected $description = 'List the most recent wide events in the log files';

    /** @var array<string, string> */
    protected array $where = [];

    protected ?int $since = null;

    public function handle(): int
    {
        // Reset: the same instance runs again under Artisan::call().
        $this->where = [];
        $this->since = null;

        foreach ((array) $this->option('where') as $filter) {
            if (! str_contains((string) $filter, '=')) {
                $this->components->error("Expected key=value, got: {$filter}");

                return self::INVALID;
            }

            [$key, $value] = explode('=', (string) $filter, 2);
            $this->where[$key] = $value;
        }

        if ($this->option('interactive') && ($this->option('follow') || $this->option('json'))) {
            $this->components->error('--interactive cannot be combined with --follow or --json.');

            return self::INVALID;
        }

        if ($since = $this->option('since')) {
            if (! preg_match('/^(\d+)([smhd])$/', (string) $since, $match)) {
                $this->components->error('Expected --since as a number and a unit, for example 15m, 2h or 1d.');

                return self::INVALID;
            }

            $unit = ['s' => 'seconds', 'm' => 'minutes', 'h' => 'hours', 'd' => 'days'][$match[2]];
            $this->since = now()->sub($unit, (int) $match[1])->getTimestamp();
            $this->input->setOption('days', max((int) $this->option('days'), (int) ceil((now()->getTimestamp() - $this->since) / 86400)));
        }

        [$files, $elsewhere] = $this->sources();
        $events = $this->recent($files, max(1, (int) $this->option('limit')));

        if ($events === [] && ! $this->option('follow')) {
            $this->components->warn("No matching event in the last {$this->option('days')} days of: ".($files ? implode(', ', $files) : 'no readable log file'));
        }

        if ($events !== [] && $this->option('interactive') && $this->input->isInteractive()) {
            $this->browse($files, $events);

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $this->print($event);
        }

        if ($events !== [] && ! $this->option('json')) {
            $this->line('<comment>Run jot:query <trace_id> for a trace, or <span_id> for every field.</comment>');
        }

        foreach ($elsewhere as $channel) {
            $this->line($channel === 'sentry_logs'
                ? '<comment>Also in Sentry Logs.</comment>'
                : "<comment>Also written to the {$channel} channel, which jot:tail cannot read.</comment>");
        }

        if ($this->option('follow')) {
            $this->follow();
        }

        return $events === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The last matching events across the files, oldest first.
     *
     * @param  list<string>  $files
     * @return list<array<string, mixed>>
     */
    protected function recent(array $files, int $limit): array
    {
        $events = [];

        // Line by line with a bounded buffer per file: a daily file can hold hundreds of megabytes.
        foreach ($files as $file) {
            $buffer = [];

            foreach (new \SplFileObject($file) as $line) {
                if (is_string($line) && ($event = $this->match($line)) !== null) {
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

        return collect($events)->sortBy('timestamp')->values()->slice(-$limit)->values()->all();
    }

    /**
     * Shows the events in a table, newest first, then every field of the one picked, until Quit.
     *
     * @param  list<string>  $files
     * @param  list<array<string, mixed>>  $events
     */
    protected function browse(array $files, array $events): void
    {
        $events = array_reverse($events);
        $span = null;

        while (true) {
            $refresh = false;
            $table = $this->eventsTable($events, $span);
            $table->on('key', function (string $key) use ($table, &$refresh) {
                if ($key === 'r' && $table->state !== 'search') {
                    $refresh = true;
                    $table->state = 'submit';
                }
            });
            $index = $table->prompt();

            if ($refresh) {
                $span = $events[$index]['span_id'] ?? null;
                $files = $this->sources()[0];
                $events = array_reverse($this->recent($files, max(1, (int) $this->option('limit'))));

                if ($events === []) {
                    $this->components->warn('No matching event left.');

                    return;
                }

                continue;
            }

            $span = null;

            if ($this->inspect($files, $events[$index], ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit']) === 'quit') {
                return;
            }
        }
    }

    /**
     * The events table, its search matching the trace and span IDs too, the cursor on the span given.
     *
     * @param  list<array<string, mixed>>  $events
     */
    protected function eventsTable(array $events, ?string $span): DataTablePrompt
    {
        $rows = array_map(fn ($event) => [
            isset($event['timestamp']) ? Carbon::parse((string) $event['timestamp'])->format('H:i:s') : '--:--:--',
            (string) ($event['entrypoint'] ?? '?'),
            $this->what($event),
            (string) ($event['duration_ms'] ?? ''),
            Str::limit((string) $this->errorMessage($event), 60),
        ], $events);

        // The filter gets the row without its key, so the IDs are looked up by the row's text; identical rows share them.
        $ids = [];
        foreach ($rows as $i => $row) {
            $key = implode("\0", $row);
            $ids[$key] = ($ids[$key] ?? '').' '.($events[$i]['trace_id'] ?? '').' '.$events[$i]['span_id'];
        }

        $table = new DataTablePrompt(
            ['Time', 'Type', 'What', 'ms', 'Error'],
            $rows,
            scroll: min(10, count($rows)),
            label: 'Recent events',
            hint: '/ search · r refresh · Enter shows every field',
            filter: fn (array $row, string $query) => str_contains(mb_strtolower(implode(' ', $row).$ids[implode("\0", $row)]), mb_strtolower($query)),
        );

        // Arrow presses through the public event API: Prompts keeps highlight() protected.
        $down = array_search($span, array_column($events, 'span_id'), true);
        for ($i = 0; $i < (int) $down; $i++) {
            $table->emit('key', Key::DOWN);
        }

        return $table;
    }

    /**
     * Polls the newest file and prints the matching lines appended to it, until interrupted.
     */
    protected function follow(): never
    {
        $file = null;
        $offset = 0;

        while (true) {
            clearstatcache();
            $newest = collect($this->sources()[0])->sortBy(fn ($path) => filemtime($path))->last();

            if ($newest !== $file) {
                // At start, skip what is printed already; a file that appears later, after the daily rollover, is read whole.
                $offset = $file === null && $newest !== null ? (int) filesize($newest) : 0;
                $file = $newest;
            }

            if ($file !== null && ($handle = @fopen($file, 'r')) !== false) {
                $offset = min($offset, (int) filesize($file));
                fseek($handle, $offset);

                // Only complete lines: the writer may be halfway through the last one.
                while (($line = fgets($handle)) !== false && str_ends_with($line, "\n")) {
                    $offset += strlen($line);

                    if (($event = $this->match($line)) !== null) {
                        $this->print($event);
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
     * @return array<string, mixed>|null
     */
    protected function match(string $line): ?array
    {
        $event = $this->decode($line);

        if (! isset($event['span_id'])
            || ($this->since !== null && strtotime((string) ($event['timestamp'] ?? '')) < $this->since)
            || ($this->option('errors') && Writer::level($event) !== LogLevel::ERROR)
            || ($this->option('slow') && ($event['duration_ms'] ?? 0) <= config('jot.sampling.slow_ms', 1000))) {
            return null;
        }

        foreach ($this->where as $key => $pattern) {
            $value = $event[$key] ?? null;

            if ($value === null || ! Str::is($pattern, is_string($value) ? $value : (string) json_encode($value))) {
                return null;
            }
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function print(array $event): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($event, JSON_UNESCAPED_SLASHES));

            return;
        }

        $time = isset($event['timestamp']) ? Carbon::parse((string) $event['timestamp'])->format('H:i:s') : '--:--:--';

        $this->line("{$time}  ".($event['trace_id'] ?? '?').'  '.$this->summary($event));
    }
}
