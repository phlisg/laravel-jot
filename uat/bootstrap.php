<?php

use Orchestra\Testbench\Foundation\Application;
use Phlisg\Jot\JotServiceProvider;

require __DIR__.'/../vendor/autoload.php';

/**
 * Runs one step, then dumps what the accumulator holds: raw fields, and each
 * section flattened the way emit will write it.
 */
function wrap(string $label, ?callable $step = null): void
{
    echo PHP_EOL.'--- '.$label.PHP_EOL;

    $step && $step();

    dump((fn () => [
        'fields' => $this->fields,
        'sections' => array_map(fn ($section) => $section->toDotNotation(), $this->sections),
    ])->call(jot()));
}

$app = Application::create(options: ['extra' => ['providers' => [JotServiceProvider::class]]]);

return $app;
