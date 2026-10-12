<?php

namespace MarvelousDigital\WideEvents\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:env')]
class EnvCommand extends Command
{
    protected $signature = 'jot:env';

    protected $description = 'Add the Jot keys missing from .env.example and .env, with their default values';

    // Must match the env() defaults in config/jot.php.
    public const KEYS = [
        'JOT_ENABLED' => 'true',
        'JOT_CHANNEL' => '',
        'JOT_TRUST_INCOMING_HEADER' => 'false',
        'APP_VERSION' => '',
        'JOT_REGION' => '',
        'JOT_STRICT' => 'false',
        'JOT_SAMPLE_RATE' => '1.0',
        'JOT_SENTRY' => 'true',
        'JOT_SENTRY_LEVEL' => 'info',
        'JOT_SENTRY_LINK' => '',
    ];

    public function handle(): int
    {
        foreach (['.env.example', '.env'] as $file) {
            $path = base_path($file);

            if (! is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);

            // A commented-out key counts as present: the app chose to leave it unset.
            $missing = array_filter(
                self::KEYS,
                fn (string $key) => ! preg_match('/^\s*#?\s*'.$key.'=/m', $content),
                ARRAY_FILTER_USE_KEY,
            );

            if (! $missing) {
                $this->components->info("$file has every Jot key.");

                continue;
            }

            $lines = array_map(fn ($key, $value) => "$key=$value", array_keys($missing), $missing);

            file_put_contents($path, rtrim($content, "\n")."\n\n".implode("\n", $lines)."\n");

            $this->components->info("$file: added ".implode(', ', array_keys($missing)).'.');
        }

        return self::SUCCESS;
    }
}
