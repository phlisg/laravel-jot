<?php

namespace MarvelousDigital\WideEvents\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MarvelousDigital\WideEvents\Writer;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:disk')]
class DiskCommand extends Command
{
    protected $signature = 'jot:disk';

    protected $description = 'Show the disk space of the Jot log files, the retention and sampling policy, and the days until the disk is full';

    public function handle(): int
    {
        $this->components->twoColumnDetail('<fg=green;options=bold>Policy</>');
        $this->components->twoColumnDetail('Sample rate', (string) config('jot.sampling.rate'));
        $this->components->twoColumnDetail('Sample rate of 4xx responses', (string) config('jot.sampling.client_errors'));
        $this->components->twoColumnDetail('Most always-kept events a minute', (string) (config('jot.sampling.max_per_minute') ?? 'no limit'));

        foreach (app(Writer::class)->outputs() as $output) {
            $channel = $output['channel'] ?? null;
            $config = $channel === null
                ? ['driver' => 'daily', 'path' => Writer::defaultPath(), 'days' => config('jot.days', 7)]
                : config("logging.channels.{$channel}");

            $this->newLine();

            if (! in_array($config['driver'] ?? null, ['single', 'daily'], true)) {
                $this->components->twoColumnDetail('<fg=green;options=bold>'.$channel.'</>', 'not a file, skipped');

                continue;
            }

            $this->printFiles($channel ?? 'default output', $config['driver'], $config['path'], (int) ($config['days'] ?? 7));
        }

        return self::SUCCESS;
    }

    protected function printFiles(string $name, string $driver, string $path, int $days): void
    {
        $daily = $driver === 'daily';
        $files = $daily
            ? glob(dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].'.pathinfo($path, PATHINFO_EXTENSION)) ?: []
            : array_filter([$path], 'is_file');
        $sizes = array_map('filesize', array_combine($files, $files));
        $total = array_sum($sizes);
        $perDay = $daily ? $this->dailyGrowth($path, $sizes) : $this->singleGrowth($path, $total);
        // Laravel's daily driver and Monolog keep every file when days is 0.
        $cap = $daily && $days > 0 ? $perDay * $days : null;
        $free = @disk_free_space(dirname($path)) ?: null;

        $this->components->twoColumnDetail('<fg=green;options=bold>'.$name.'</>', $daily ? dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-*.log' : $path);
        $this->components->twoColumnDetail('Retention', match (true) {
            ! $daily => 'one file, never deleted',
            $days > 0 => "{$days} days",
            default => 'every file kept',
        });
        $this->components->twoColumnDetail('Size', count($files).' '.Str::plural('file', count($files)).', '.$this->size($total));
        $this->components->twoColumnDetail('Growth', $perDay === null ? 'unknown' : $this->size($perDay).' a day');

        if ($cap !== null && $perDay !== null) {
            $this->components->twoColumnDetail('Size at retention', $this->size($cap));
        }

        $this->components->twoColumnDetail('Disk free', $free === null ? 'unknown' : $this->size($free));
        $this->components->twoColumnDetail('Disk full in', $this->fullIn($total, $perDay, $cap, $free));
    }

    // Number::fileSize() needs the intl extension.
    protected function size(float $bytes): string
    {
        $unit = $bytes > 0 ? min(4, (int) floor(log($bytes, 1024))) : 0;

        return round($bytes / 1024 ** $unit).' '.['B', 'KB', 'MB', 'GB', 'TB'][$unit];
    }

    protected function fullIn(int $total, ?float $perDay, ?float $cap, ?float $free): string
    {
        if ($perDay === null || $free === null) {
            return 'unknown';
        }

        if ($perDay <= 0 || ($cap !== null && $cap - $total < $free)) {
            return '<fg=green>never</>';
        }

        $days = (int) floor($free / $perDay);

        return $days < 30 ? "<fg=red>{$days} days</>" : "{$days} days";
    }

    /**
     * Average size of the past days' files. Today's file is partial, so it only counts when it is the only one.
     *
     * @param  array<string, int>  $sizes
     */
    protected function dailyGrowth(string $path, array $sizes): ?float
    {
        $today = dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-'.now()->format('Y-m-d').'.'.pathinfo($path, PATHINFO_EXTENSION);
        $past = array_diff_key($sizes, [$today => true]);

        if ($past !== []) {
            return array_sum($past) / count($past);
        }

        $elapsed = now()->getTimestamp() - now()->startOfDay()->getTimestamp();

        return isset($sizes[$today]) && $elapsed > 0 ? $sizes[$today] / $elapsed * 86400 : null;
    }

    /**
     * Size divided by the age of the first line, read from its timestamp.
     */
    protected function singleGrowth(string $path, int $total): ?float
    {
        $first = is_file($path) ? (string) (new \SplFileObject($path))->fgets() : '';

        if (! preg_match('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $first, $match)) {
            return null;
        }

        $seconds = now()->getTimestamp() - strtotime($match[0]);

        return $seconds > 0 ? $total / $seconds * 86400 : null;
    }
}
