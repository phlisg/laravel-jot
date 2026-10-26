<?php

namespace Phlisg\WideEvents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Phlisg\WideEvents\Jot
 */
class Jot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Phlisg\WideEvents\Jot::class;
    }
}
