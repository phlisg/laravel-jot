<?php

namespace MarvelousDigital\WideEvents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MarvelousDigital\WideEvents\Jot
 */
class Jot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MarvelousDigital\WideEvents\Jot::class;
    }
}
