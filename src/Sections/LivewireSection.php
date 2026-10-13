<?php

namespace MarvelousDigital\WideEvents\Sections;

use LogicException;
use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Section;

/**
 * What a Livewire update request did. Names and paths only, never values.
 */
final class LivewireSection extends Section
{
    private const MAX_LIST_SIZE = 10;

    // The path of the page the components sit on.
    public ?string $page = null;

    // Lists of names: component::method in calls, wire:model paths in updates.
    public ?array $components = null;

    public ?array $calls = null;

    public ?array $updates = null;

    public ?array $events = null;

    public ?bool $lazy = null;

    // Livewire 4 only: Livewire 3 sends a poll as a normal call.
    public ?bool $poll = null;

    public ?bool $validationFailed = null;

    public ?array $validationFields = null;

    #[Key('filament.action')]
    public ?string $filamentAction = null;

    /**
     * Adds a value to a list once, up to MAX_LIST_SIZE: one request can carry many components.
     */
    public function remember(string $list, string $value): void
    {
        if (! property_exists($this, $list)) {
            throw new LogicException("LivewireSection has no list {$list}.");
        }

        $values = $this->{$list} ?? [];

        if (count($values) < self::MAX_LIST_SIZE && ! in_array($value, $values, true)) {
            $this->{$list} = [...$values, $value];
        }
    }
}
