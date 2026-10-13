<?php

namespace MarvelousDigital\WideEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MarvelousDigital\WideEvents\Commands\Concerns\ReadsEvents;
use MarvelousDigital\WideEvents\Writer;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:tail')]
class TailCommand extends Command
{
    use ReadsEvents;

    protected $signature = 'jot:tail
        {--limit=20 : How many events to print}
        {--where=* : Field filter, key=value, repeatable; the value may use * wildcards}
        {--errors : Only events with an error.type, a 5xx status or a failed job}
        {--slow : Only events slower than jot.sampling.slow_ms}
        {--since= : Only events newer than this, for example 15m, 2h or 1d}
        {--days=1 : How many days of log files to read}
        {--follow : Keep printing new events as they are written}
        {--json : Print the events as JSON lines}';

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

            usleep(500_000);
        }
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
