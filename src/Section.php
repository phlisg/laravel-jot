<?php

namespace Phlisg\WideEvents;

use Illuminate\Support\Str;
use Phlisg\WideEvents\Exceptions\JotSectionPrefixConflict;

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

    /** The instance of this section in the running event. */
    final public static function current(): static
    {
        $section = jot(static::class);

        if (! $section instanceof static) {
            throw JotSectionPrefixConflict::for(static::class, $section);
        }

        return $section;
    }

    final public function toDotNotation(): array
    {
        return static::flattenSection($this, static::prefix());
    }
}
