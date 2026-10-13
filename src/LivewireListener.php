<?php

namespace MarvelousDigital\WideEvents;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MarvelousDigital\WideEvents\Sections\LivewireSection;

/**
 * Fills the Livewire section of a Livewire update request from Livewire's hooks.
 */
class LivewireListener
{
    /**
     * Each listener returns nothing: Livewire calls back a listener's return value when the request ends.
     */
    public function register(): void
    {
        if (! app()->bound('livewire') || ! config('jot.fields.livewire', true)) {
            return;
        }

        Livewire::listen('hydrate', function ($component, $memo) {
            $this->livewire(function (LivewireSection $livewire) use ($component, $memo) {
                $livewire->page ??= $memo['path'] ?? null;
                $livewire->remember('components', $component->getName());
            });
        });

        Livewire::listen('update', function ($component, $path) {
            $this->livewire(fn (LivewireSection $livewire) => $livewire->remember('updates', $path));
        });

        // $metadata: Livewire 4 only.
        Livewire::listen('call', function ($component, $method, $params, $context, $returnEarly, $metadata = []) {
            $this->livewire(fn (LivewireSection $livewire) => $this->recordCall($livewire, $component, $method, $params, $metadata));
        });

        Livewire::listen('exception', function ($component, $e) {
            if ($e instanceof ValidationException) {
                $this->livewire(function (LivewireSection $livewire) use ($e) {
                    $livewire->validationFailed = true;

                    foreach (array_keys($e->errors()) as $field) {
                        $livewire->remember('validationFields', (string) $field);
                    }
                });
            }
        });
    }

    protected function recordCall(LivewireSection $livewire, object $component, string $method, array $params, array $metadata): void
    {
        if (($metadata['type'] ?? null) === 'poll') {
            $livewire->poll = true;
        }

        match ($method) {
            '__dispatch' => $livewire->remember('events', (string) ($params[0] ?? '')),
            '__lazyLoad' => $livewire->lazy = true,
            default => null,
        };

        // Livewire internals, such as uploads, and Livewire 4 magic actions, such as $refresh.
        if (Str::startsWith($method, ['_', '$'])) {
            return;
        }

        $livewire->remember('calls', $component->getName().'::'.$method);
        $livewire->filamentAction = $this->filamentAction($component, $method, $params) ?? $livewire->filamentAction;
    }

    protected function filamentAction(object $component, string $method, array $params): ?string
    {
        $name = null;

        // mountAction(name), mountTableAction(name), mountFormComponentAction(component, name)...
        if (str_starts_with($method, 'mount') && str_ends_with($method, 'Action')) {
            $name = $params[$method === 'mountFormComponentAction' ? 1 : 0] ?? null;
        } elseif (str_starts_with($method, 'callMounted')) {
            // callMountedAction() reads $mountedActions: names in Filament 3, ['name' => ...] in Filament 4.
            $list = lcfirst(substr($method, 4)).'s';
            $action = property_exists($component, $list) ? Arr::last((array) $component->{$list}) : null;
            $name = is_array($action) ? ($action['name'] ?? null) : $action;
        }

        return is_string($name) ? $name : null;
    }

    /**
     * @param  Closure(LivewireSection): mixed  $change
     */
    protected function livewire(Closure $change): void
    {
        // Update requests only: the exception hook also fires on the first page load.
        if (jot()->traceId() && Str::endsWith((string) request()->route()?->getName(), 'livewire.update')) {
            $change(jot()->reserve(new LivewireSection));
        }
    }
}
