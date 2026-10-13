# Livewire

With Livewire 3 or 4 installed, each Livewire update request records what its components did. Livewire sends every click, live input change and poll as a `POST` to its update route, so without these fields all those events look alike.

A registration form, submitted with an invalid email:

```json
{
  "http.route": "livewire.update",
  "http.response.status_code": 200,
  "livewire.page": "events/42/register",
  "livewire.components": ["registration-form"],
  "livewire.calls": ["registration-form::submit"],
  "livewire.updates": ["email"],
  "livewire.validation_failed": true,
  "livewire.validation_fields": ["email"]
}
```

- `livewire.page`: the path of the page the components sit on, without the leading slash.
- `livewire.components`: the component names. One request can carry several components.
- `livewire.calls`: the methods called, as `component::method`.
- `livewire.updates`: the property paths changed through `wire:model`.
- `livewire.events`: the names of the events dispatched to the components.
- `livewire.lazy`: `true` when a lazy component loads.
- `livewire.poll`: `true` on a `wire:poll` request. Livewire 4 only.
- `livewire.validation_failed`, `livewire.validation_fields`: a failed validation and the fields that failed. Livewire answers it with a 200 and never reports it, so these fields are its only trace.
- `filament.action`: the Filament action the user opened or ran, such as `delete`.

Each list holds up to 10 distinct entries. The first page load writes a plain HTTP event. File uploads have their own routes, visible in `http.route`. The switch is `fields.livewire` in `config/jot.php`.

> Security: the package logs method names, property paths and event names, never values or parameters. What a user types in a form, such as an email or a password, stays out of the event.

## Polling

Jot drops the requests of `wire:poll` by default, so most apps need nothing. A poll that sampling always keeps, such as an error, is still written, see [Sampling](../README.md#sampling).

- Livewire 4: every poll is dropped.
- Livewire 3 and 4: the loads of `#[Lazy]` components are dropped too.
- Livewire 3: a poll that only refreshes is dropped. A poll that calls a method, such as `wire:poll="checkStatus"`, looks like a click and is kept. Drop it with a [rule](../README.md#rules), `*` on both sides because `livewire.calls` is a list:

```php
Jot::dropWhen(['livewire.calls' => '*::checkStatus*']);
```

To keep every poll, set `sampling.drop_livewire_polls` to `false` in `config/jot.php`. Detection reads the Livewire fields, so it needs `fields.livewire` on.
