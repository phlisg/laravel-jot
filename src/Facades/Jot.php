<?php

namespace Phlisg\Jot\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Phlisg\Jot\Jot
 */
class Jot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Phlisg\Jot\Jot::class;
    }
}
