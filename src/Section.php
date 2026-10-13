<?php

namespace MarvelousDigital\WideEvents;

use Illuminate\Support\Str;

abstract class Section
{
    use Utils;

    /**
     * Defines an automatic prefix from class name. You may override it using $prefix
     * */
    final public static function prefix(): string
    {
        static $prefixes = [];

        if (static::$prefix) {
            return $prefixes[static::class] = static::$prefix;
        }

        return $prefixes[static::class] ??= Str::snake(
            preg_replace('/(Log|Section)$/', '', class_basename(static::class))
        );
    }

    protected static ?string $prefix = null;

    final public function toDotNotation(): array
    {
        return static::flattenSection($this, static::prefix());
    }
}
