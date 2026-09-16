<?php

namespace Phlisg\Jot;

use BackedEnum;
use Illuminate\Support\Str;
use Phlisg\Jot\Attributes\Hide;
use ReflectionObject;
use ReflectionProperty;

trait Utils
{
    /**
     * Flattening rules live in the README, see "Base class".
     */
    protected static function walkArrayToDot(array $values, string $prefix): array
    {
        $dotted = [];

        foreach ($values as $key => $value) {
            $dotKey = $prefix.'.'.Str::snake((string) $key);

            if ($value === null) {
                continue;
            }

            if ($value instanceof Section) {
                $dotted = array_merge($dotted, static::walkArrayToDot(static::getExportableProperties($value), $dotKey));

                continue;
            }

            if ($value instanceof BackedEnum) {
                $dotted[$dotKey] = $value->value;

                continue;
            }

            if (is_array($value) && ! array_is_list($value)) {
                $dotted = array_merge($dotted, static::walkArrayToDot($value, $dotKey));

                continue;
            }

            $dotted[$dotKey] = $value;
        }

        return $dotted;
    }

    /**
     * Public properties of a section, minus the ones marked #[Hide].
     */
    protected static function getExportableProperties(Section $section): array
    {
        $exported = [];

        foreach ((new ReflectionObject($section))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Hide::class)) {
                continue;
            }

            $exported[$property->getName()] = $property->getValue($section);
        }

        return $exported;
    }
}
