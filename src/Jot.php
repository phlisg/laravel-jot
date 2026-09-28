<?php

namespace Phlisg\Jot;

use BackedEnum;
use InvalidArgumentException;
use Phlisg\Jot\Exceptions\JotSectionRequiresInitialisation;
use ReflectionClass;
use ReflectionException;

class Jot {
    use Utils;

    protected array $sections = [];
    protected array $fields = []; // The resolved fields with their values, flattened at emit-time
    protected array $owners = []; // field => 'a string key', 'an enum key' or a section class

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

        foreach ($this->pairs($key, $value) as [$field, $fieldValue, $owner]) {
            $this->appendKey($field, $fieldValue, $owner);
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

        foreach ($this->pairs($key, $value) as [$field, $fieldValue, $owner]) {
            if (! array_key_exists($field, $this->fields)) {
                $this->appendKey($field, $fieldValue, $owner);
            }
        }

        return $this;
    }

    public function increment(string|array|BackedEnum $key, int $by = 1): static
    {
        foreach ($this->pairs($key, 0) as [$field, $start, $owner]) {
            $current = $this->fields[$field] ?? $start;

            // A non-numeric field is left alone: logging never breaks the app.
            if (is_numeric($current)) {
                $this->appendKey($field, $current + $by, $owner);
            }
        }

        return $this;
    }

    public function push(string|array|BackedEnum $key, mixed $value): static
    {
        foreach ($this->pairs($key, $value) as [$field, $pushed, $owner]) {
            $current = $this->fields[$field] ?? [];

            if (is_array($current)) {
                $current[] = $pushed;
                $this->appendKey($field, $current, $owner);
            }
        }

        return $this;
    }

    /**
     * Every key form as [field, value, owner] triples. A list of keys, from
     * increment() and push(), shares the same value.
     *
     * @return list<array{string, mixed, string}>
     */
    protected function pairs(string|array|BackedEnum $key, mixed $value): array
    {
        if (! is_array($key)) {
            return [$this->pair($key, $value)];
        }

        if (array_is_list($key)) {
            return array_map(fn ($listed) => $this->pair($listed, $value), $key);
        }

        $pairs = [];

        foreach ($key as $field => $fieldValue) {
            $pairs[] = $this->pair($field, $fieldValue);
        }

        return $pairs;
    }

    /**
     * @return array{string, mixed, string}
     */
    protected function pair(string|int|BackedEnum $key, mixed $value): array
    {
        $field = $this->fieldName($key);
        $owner = $this->ownerOf($key);

        if ($owner === 'string' && config('jot.strict', false)) {
            logger()->warning(sprintf('[jot] String key "%s" written; declare it in an enum or a section.', $field));
        }

        return [$field, $value, $owner];
    }

    protected function fieldName(string|int|BackedEnum $key): string
    {
        return (string) ($key instanceof BackedEnum ? $key->value : $key);
    }

    protected function ownerOf(string|int|BackedEnum $key): string
    {
        return $key instanceof BackedEnum ? 'enum' : 'string';
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function appendSection(Section|string $section) : void
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

   protected function appendKey(string $field, mixed $value, string $owner): void
   {
       $previous = $this->owners[$field] ?? null;

       // See "Key collisions" in the README. Section keys get their owner at emit.
       if ($previous !== null && $previous !== $owner) {
           logger()->warning(sprintf(
               '[jot] Key "%s" was already added by %s, then overwritten by %s.',
               $field,
               $previous,
               $owner,
           ));
       }

       $this->fields[$field] = $value;
       $this->owners[$field] = $owner;
   }
}
