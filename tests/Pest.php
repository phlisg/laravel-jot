<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use MarvelousDigital\WideEvents\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Records the warnings logged from now on. Call the returned closure to read them.
 *
 * @return Closure(): list<string>
 */
function warnings(): Closure
{
    $warnings = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $log) use ($warnings) {
        if ($log->level === 'warning') {
            $warnings[] = $log->message;
        }
    });

    return fn () => $warnings->getArrayCopy();
}
