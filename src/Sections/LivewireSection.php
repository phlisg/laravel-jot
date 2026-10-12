<?php

namespace MarvelousDigital\WideEvents\Sections;

use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Section;

/**
 * What a Livewire update request did. Names and paths only, never values.
 */
final class LivewireSection extends Section
{
    public const MAX = 10;

    // The path of the page the components sit on.
    public ?string $page = null;

    public ?array $components = null;

    // component::method
    public ?array $calls = null;

    // wire:model paths
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
     * Adds a value to a list once, up to MAX: one request can carry many components.
     */
    public function remember(string $list, string $value): void
    {
        $values = $this->{$list} ?? [];

        if (count($values) < self::MAX && ! in_array($value, $values, true)) {
            $this->{$list} = [...$values, $value];
        }
    }
}
