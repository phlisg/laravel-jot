# Livewire

With Livewire 3 or 4 installed, Jot adds `livewire.*` fields to each Livewire update request, and drops the requests of `wire:poll`, see [Polling](#polling). Nothing to configure.

Livewire sends every click, live input change and poll as a `POST` to its update route, `livewire.update`. Without these fields, all those events look alike.

## Fields

A registration form, submitted with an invalid email:

```json
{
  "entrypoint": "http",
  "http.request.method": "POST",
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

| Field | Holds |
|---|---|
| `livewire.page` | the path of the page the components sit on, without the leading slash |
| `livewire.components` | the component names. One request can carry several components |
| `livewire.calls` | the methods called, as `component::method` |
| `livewire.updates` | the property paths changed through `wire:model` |
| `livewire.events` | the names of the events dispatched to the components |
| `livewire.lazy` | `true` when a `#[Lazy]` component loads |
| `livewire.poll` | `true` on a `wire:poll` request. Livewire 4 only |
| `livewire.validation_failed` | `true` when validation failed. Livewire answers it with a 200 and never reports it, so this field is its only trace |
| `livewire.validation_fields` | the fields that failed validation |
| `filament.action` | the Filament action the user opened or ran, such as `delete` |

- Each list holds up to 10 distinct entries.
- Fields with nothing to record stay out of the event.
- `livewire.calls` leaves out Livewire internals (methods starting with `_`) and magic actions such as `$refresh` (starting with `$`).
- The first page load writes a plain HTTP event, without `livewire.*` fields.
- File uploads have their own routes, visible in `http.route`.
- `fields.livewire` in `config/jot.php` turns the fields off. It is on by default.

> Security: Jot records method names, property paths and event names, never values or parameters. What a user types in a form, such as an email or a password, stays out of the event.

To list the failed validations of the last hour:

```sh
php artisan jot:query --where=livewire.validation_failed=true --since=1h
```

## Polling

Jot drops `wire:poll` requests by default, so a page that polls every few seconds fills no log. A poll that sampling always keeps, such as an error, is still written, see [Sampling](../README.md#sampling).

Jot treats a Livewire update as a poll when it carries `livewire.poll`, or when it has components but no call, no `wire:model` update and no event.

| Request | Livewire 3 | Livewire 4 |
|---|---|---|
| `wire:poll` with no method | dropped: Livewire 3 sends it as a `$refresh` call, which Jot leaves out of `livewire.calls` | dropped: Livewire 4 marks it as a poll |
| `wire:poll="checkStatus"` | kept: it looks like a click on `checkStatus` | dropped |
| `#[Lazy]` component load | dropped | dropped |

To drop a Livewire 3 poll that calls a method, add a [rule](../README.md#rules) on `livewire.calls`. Put `*` on both sides, since the list is compared as JSON (`["dashboard::checkStatus"]`):

```php
Jot::dropWhen(['livewire.calls' => '*::checkStatus*']);
```

To keep every poll, set `sampling.drop_livewire_polls` to `false` in `config/jot.php`. Detection reads the Livewire fields, so it needs `fields.livewire` on.
