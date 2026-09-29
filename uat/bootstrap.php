<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Foundation\Application;
use Phlisg\Jot\JotServiceProvider;

require __DIR__.'/../vendor/autoload.php';

/**
 * Runs one step, then dumps what the accumulator holds: raw fields, each
 * section flattened the way emit will write it, and what the step logged.
 */
function wrap(string $label, ?callable $step = null): void
{
    static $logged = null;

    if ($logged === null) {
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
            $logged[] = "{$log->level}: {$log->message}";
        });
    }

    $logged = [];

    echo PHP_EOL.'--- '.$label.PHP_EOL;

    $step && $step();

    dump((fn () => [
        'reserved' => $this->reserved,
        'fields' => $this->fields,
        'sections' => array_map(fn ($section) => $section->toDotNotation(), $this->sections),
    ])->call(jot()) + ['event' => wrapEvent(), 'logs' => $logged]);
}

/**
 * The final event, or the error that stopped it, so one broken step still shows the rest.
 */
function wrapEvent(): array|string
{
    try {
        return jot()->event();
    } catch (Throwable $e) {
        return $e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine();
    }
}

$app = Application::create(options: ['extra' => ['providers' => [JotServiceProvider::class]]]);

return $app;
