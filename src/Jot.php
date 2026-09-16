<?php

namespace Phlisg\Jot;

use BackedEnum;
use InvalidArgumentException;
use Phlisg\Jot\Exceptions\JotSectionRequiresInitialisation;
use ReflectionClass;
use ReflectionException;

class Jot
{
    use Utils;

    protected array $sections = [];

    protected array $fields = []; // The resolved fields with their values, flattened at emit-time

    final public function getSection(Section|string $section): ?Section
    {
        if (is_subclass_of($section, Section::class)) {
            $this->appendSection($section);
        }

        return $this->sections[$section::prefix()];
    }

    public function add(string|array|BackedEnum|Section $key, mixed $value = null): static
    {
        if ($key instanceof Section) {
            $this->appendSection($key);

            return $this;
        }

        foreach ($this->pairs($key, $value) as $field => $fieldValue) {
            $this->appendKey($field, $fieldValue);
        }

        return $this;
    }

    public function addIfMissing(string|array|BackedEnum|Section $key, mixed $value = null): static
    {
        if ($key instanceof Section) {
            if (! isset($this->sections[$key::prefix()])) {
                $this->appendSection($key);
            }

            return $this;
        }

        foreach ($this->pairs($key, $value) as $field => $fieldValue) {
            if (! array_key_exists($field, $this->fields)) {
                $this->appendKey($field, $fieldValue);
            }
        }

        return $this;
    }

    public function increment(string|array|BackedEnum $key, int $by = 1): static
    {
        foreach ($this->pairs($key, 0) as $field => $start) {
            $current = $this->fields[$field] ?? $start;

            // A non-numeric field is left alone: logging never breaks the app.
            if (is_numeric($current)) {
                $this->fields[$field] = $current + $by;
            }
        }

        return $this;
    }

    public function push(string|array|BackedEnum $key, mixed $value): static
    {
        foreach ($this->pairs($key, $value) as $field => $pushed) {
            $current = $this->fields[$field] ?? [];

            if (is_array($current)) {
                $current[] = $pushed;
                $this->fields[$field] = $current;
            }
        }

        return $this;
    }

    /**
     * Every key form as one key => value map. A list of keys, from increment()
     * and push(), shares the same value.
     *
     * @return array<string, mixed>
     */
    protected function pairs(string|array|BackedEnum $key, mixed $value): array
    {
        if (! is_array($key)) {
            return [$this->fieldName($key) => $value];
        }

        if (array_is_list($key)) {
            return array_fill_keys(array_map($this->fieldName(...), $key), $value);
        }

        $pairs = [];

        foreach ($key as $field => $fieldValue) {
            $pairs[$this->fieldName($field)] = $fieldValue;
        }

        return $pairs;
    }

    protected function fieldName(string|int|BackedEnum $key): string
    {
        return (string) ($key instanceof BackedEnum ? $key->value : $key);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function appendSection(Section|string $section): void
    {
        if (! is_subclass_of($section, Section::class)) {
            throw new InvalidArgumentException('Section must be a subclass of '.Section::class);
        }

        if (! is_string($section)) {
            $this->sections[$section::prefix()] = $section;

            return;
        }

        if (isset($this->sections[$section::prefix()])) {
            return;
        }

        if ((new ReflectionClass($section))->getConstructor()?->getNumberOfRequiredParameters()) {
            throw JotSectionRequiresInitialisation::for($section);
        }

        $this->sections[$section::prefix()] = new $section;
    }

    protected function appendKey(string $key, mixed $value = null): void
    {
        if (isset($this->fields[$key])) {
            $currentKey = gettype($this->fields[$key]);
            $message = "[jot] Key '{$key}' already exists as $currentKey and will be overwritten.";
            logger()->warning($message);
            if (app()->runningInConsole()) {
                echo $message.PHP_EOL;
            }
        }

        $this->fields[$key] = $value;
    }
}
