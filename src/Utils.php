<?php

namespace Phlisg\WideEvents;

use BackedEnum;
use Illuminate\Support\Str;
use Phlisg\WideEvents\Attributes\Hide;
use Phlisg\WideEvents\Attributes\Key;
use ReflectionObject;
use ReflectionProperty;

trait Utils
{
    /**
     * Flattening rules: see "Flattening rules" in docs/fields.md.
     */
    protected static function walkArrayToDot(array $values, ?string $prefix = null): array
    {
        $dotted = [];

        foreach ($values as $key => $value) {
            $dotKey = ($prefix ? $prefix.'.' : '').Str::snake((string) $key);
            $dotted = array_merge($dotted, static::flattenValue($dotKey, $value));
        }

        return $dotted;
    }

    protected static function flattenSection(Section $section, string $prefix): array
    {
        $dotted = [];

        foreach (static::getExportableProperties($section, $prefix) as $key => $value) {
            $dotted = array_merge($dotted, static::flattenValue($key, $value));
        }

        return $dotted;
    }

    protected static function flattenValue(string $key, mixed $value): array
    {
        return match (true) {
            $value === null => [],
            $value instanceof Section => static::flattenSection($value, $key),
            $value instanceof BackedEnum => [$key => $value->value],
            is_array($value) && ! array_is_list($value) => static::walkArrayToDot($value, $key),
            default => [$key => $value],
        };
    }

    /**
     * Public properties of a section, minus the ones marked #[Hide], keyed by
     * their final key: the #[Key] name when set, otherwise prefix.property_name.
     */
    protected static function getExportableProperties(Section $section, string $prefix): array
    {
        $exported = [];

        foreach ((new ReflectionObject($section))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getAttributes(Hide::class) || ! $property->isInitialized($section)) {
                continue;
            }

            $key = $property->getAttributes(Key::class)[0] ?? null;

            $exported[$key?->newInstance()->name ?? $prefix.'.'.Str::snake($property->getName())] = $property->getValue($section);
        }

        return $exported;
    }
}
