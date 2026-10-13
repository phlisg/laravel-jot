# Fields

How the app adds fields to a wide event, and which fields Jot adds itself. The short version is in the README, see [Adding fields](../README.md#adding-fields).

## Methods

The method names follow Laravel Context, so they read the same:

```php
jot()->add('payment.provider', 'stripe');
jot()->add([
    'booking.event_id' => $event->id,
    'booking.event_slug' => $event->slug,
]);
jot()->addIfMissing('payment.currency', 'eur');
jot()->increment('payment.attempts');
jot()->push('payment.steps', 'intent_created');   // list field

jot(PaymentLog::class)->status = PaymentStatus::Declined;   // typed section, see "Sections" below
```

Fields never carry over from one request to the next, and each job, command or scheduled task starts with none.

### Facade

The `Jot` facade exposes the same methods. Use it when the app already has its own `jot()` function, or in service providers and tests:

```php
use MarvelousDigital\WideEvents\Facades\Jot;

Jot::add('payment.provider', 'stripe');
```

Without an import, the root alias works too: `\Jot::add(...)`. The pair mirrors Laravel's own `logger()` helper and `Log` facade.

> The package declares `jot()` inside `if (! function_exists('jot'))`. If another package or the app defines `jot()` first, that definition wins and no error occurs. Use the facade in that case.

## Loose fields

A loose field has a string or enum key and belongs to no section. The app adds a field in one of three ways:

```php
jot()->add('feature.new_checkout', true);       // string key
jot()->add(Field::ExperimentVariant, 'b');      // backed enum key
jot(ExperimentLog::class)->variant = 'b';        // section property, see Sections
```

| Key | Use it for | Risk |
|---|---|---|
| String | a one-off field, a field added while debugging, a short experiment | a typo creates a new field, and nothing stops `'42'` in one place and `42` in another |
| Backed enum | fields used in many places with no natural group, such as feature flags or experiments | the enum fixes the name, the value type stays free |
| Section | a group of related fields, such as a payment | none: typed properties fix both the name and the type |

A backed enum key is any string-backed enum the app declares, one case per field:

```php
enum Field: string
{
    case ExperimentVariant = 'experiment.variant';
    case NewCheckout = 'feature.new_checkout';
}
```

### Strict mode

String keys cost nothing to add, so they spread. Each one is a field name that lives only in the code that writes it, and a wrong type on one of them breaks queries on that field, see [Field conventions](#field-conventions).

Strict mode finds them. With `JOT_STRICT=true`, the package still writes every string key, and logs a warning for each one on the app's default log channel:

```
[jot] String key "feature.new_checkout" written; declare it in an enum or a section.
```

Enum keys and sections never trigger the warning. Turn strict mode on in local and CI (continuous integration) environments to catch string keys before they reach production. Keep it off in production, where the warnings only add noise.

## Sections

A section is a class that groups related fields under one key prefix, for example `payment`. It extends `MarvelousDigital\WideEvents\Section` and declares typed, public properties: they are the schema for that group.

```php
use MarvelousDigital\WideEvents\Section;

final class PaymentLog extends Section
{
    public ?PaymentProvider $provider = null;
    public ?string $intentId = null;
    public ?PaymentStatus $status = null;
    public int $attempts = 0;
    public ?int $latencyMs = null;
}

enum PaymentStatus: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Error = 'error';
}
```

The prefix comes from the class name in snake case, without a `Log` or `Section` suffix: `PaymentLog` writes under `payment`. To pick another prefix, set it on the class:

```php
protected static ?string $prefix = 'payment';
```

`jot(PaymentLog::class)` returns the same instance for the whole request, so any class can fill it:

```php
$payment = jot(PaymentLog::class);
$payment->provider = PaymentProvider::Stripe;
$payment->attempts++;

// Later, in another class, during the same request
jot(PaymentLog::class)->status = PaymentStatus::Declined;
```

The emitted event contains:

```json
{
  "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
  "span_id": "00f067aa0ba902b7",
  "http.request.method": "POST",
  "http.route": "checkout.store",
  "http.response.status_code": 402,
  "duration_ms": 412,
  "payment.provider": "stripe",
  "payment.status": "declined",
  "payment.attempts": 1
}
```

### Flattening rules

- Exported properties: public ones only. Protected and private properties stay out of the event.
- Keys: the prefix, a dot, then the property name in snake case. `latencyMs` becomes `payment.latency_ms`.
- Backed enums: written as their backing value.
- `null` properties: skipped.
- Lists: written as one value. `['intent_created', 'charged']` stays a list under one key.
- Nested `Section` properties: flattened into deeper keys, for example `cart.coupon.code`.

### Attributes

Two attributes change the rules for one property:

- `#[Hide]`: keeps a public property out of the event, for a value the code needs but the log must not hold, such as a card token.
- `#[Key('url.path')]`: writes the property under that exact key, with no section prefix. Use it for OpenTelemetry names, which rarely match a prefix and a property name.

```php
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use MarvelousDigital\WideEvents\Attributes\Hide;
use MarvelousDigital\WideEvents\Attributes\Key;

final class CheckoutLog extends Section
{
    #[Key(UrlAttributes::URL_PATH)]
    public ?string $path = null;          // written as url.path

    #[Hide]
    public ?string $cardToken = null;     // never written

    public ?string $step = null;          // written as checkout.step
}
```

> The `open-telemetry/sem-conv` package, a dependency of Jot, holds every OpenTelemetry name as a constant. A typo in a constant fails in PHP, a typo in a string key goes to the log.

### Where sections live

Keep sections in `app/Jot`, one class per domain, for example `App\Jot\StripePayment`. Create one with:

```sh
php artisan jot:section StripePayment
```

A section with a constructor is added in one call:

```php
use App\Jot\StripePayment;

jot()->add(new StripePayment(
    intentId: $intent->id,
    amountCents: $intent->amount,
    latencyMs: $latencyMs,
));
```

- Adding a second instance of the same class replaces the first.
- `jot(StripePayment::class)` returns the added instance, and creates it on first call when the class has no required constructor arguments.
- A class with required constructor arguments cannot be created by the package, so `jot(StripePayment::class)` throws until the instance is added with `jot()->add(new ...)`. The exception names the class and the call to make.
- `jot()` accepts `Section` subclasses only, and throws on any other class.

Section classes can extend each other, for example a `Payment` section with shared fields, extended by `StripePayment` and `PaypalPayment`. A subclass inherits its parent's prefix. Adding both collides, so give the subclass its own `$prefix`, see [Key collisions](#key-collisions).

A Pest architecture test keeps `app/Jot` consistent:

```php
arch('jot sections')
    ->expect('App\Jot')
    ->toExtend(MarvelousDigital\WideEvents\Section::class);
```

## Key collisions

String keys, enum keys and sections can target the same key. A collision never throws: logging must not break the app, even when `APP_ENV` is set wrong. The README table, [Key collisions](../README.md#key-collisions), sums up each case. The details:

- Jot remembers who wrote each key: a string key, an enum key, a section class, the `Jot::user()` callback or `jot()->level()`. A second write from the same source is a normal update. A write from another source keeps the new value and logs a warning.
- Sections are flattened on top of the loose fields when the event is built, so a section property wins over a loose field with the same key, whatever the order of the writes.
- Sections are stored by prefix. Two classes with one prefix share one slot: adding an instance replaces the other class's instance, and `jot(Other::class)` returns the instance already there. Give each class its own `$prefix`.
- The fields Jot adds itself, listed in [Automatic fields](#automatic-fields), always win over app fields with the same key. With a user logged in, `jot()->add('user.id', 5)` gives way to that user's ID, with a warning.
- A value and child keys under one name (`payment` next to `payment.provider`) cannot both exist in the nested output shape, so Jot drops the value.

The warning goes to the app's default log channel and names the key and both writers:

```
[jot] Key "payment.provider" was already added by string, then overwritten by App\Jot\StripePayment.
```

## Field conventions

Log backends such as Elasticsearch fix a field's type the first time they see it. A later value of another type causes a mapping conflict, and queries on that field fail. These rules keep every field queryable:

- One type per key, forever. Typed section properties enforce this in PHP.
- Namespaced keys: `payment.provider` over a bare `provider`.
- Singular prefixes: `payment`, `booking`.
- Units in the name: `latency_ms`, `total_cents`.
- Backed enums for any field used to filter or group, such as status, provider, error type, or plan tier.
- IDs in values: `user.id = 42`. A key such as `user_42.plan` creates one field per user.
- Domain words for prefixes. A booking app uses `booking.event_id`: a bare `event.*` prefix reads like the wide event itself or a Laravel event.

## Automatic fields

The package adds these fields with no code in the app. Each group has a switch under `fields` in `config/jot.php`, see [Configuration](configuration.md).

Field names follow the OpenTelemetry semantic conventions where one exists. Fields with no OpenTelemetry equivalent, such as `session.hash` or `laravel.version`, keep a Jot name.

Always on:

- `trace_id`: shared by a request and every job and command it starts, so one query finds them all. New at the start of each trace. The `X-Request-Id` response header returns it.
- `span_id`: this event's own ID.
- `parent_span_id`: the `span_id` of the request, job or command that started this one. Absent on the first event of a trace.
- `timestamp`: start time, in ISO 8601 format.
- `entrypoint`: where the unit of work entered the app: `http`, `queue`, `console` or `schedule`.
- `origin`: the `entrypoint` of the first event in the trace. A job dispatched by a scheduled task has `entrypoint=queue` and `origin=schedule`.
- `duration_ms`: time from start to emit.

The IDs follow [W3C Trace Context](https://www.w3.org/TR/trace-context/): 32 hexadecimal characters for `trace_id`, 16 for the span IDs.

`traceparent` is the W3C header that carries a trace ID from one service to the next. With `trace.trust_incoming` on, a `traceparent` header on the request sets `trace_id` and `parent_span_id`, and an incoming `X-Request-Id` goes to `http.request.header.x-request-id`. Turn it on only behind a proxy that sets or strips both headers: otherwise any client can pick the trace ID of its own requests.

By group:

| Group | Fields | Default |
|---|---|---|
| `http` | `http.request.method`, `http.route` (the route name, such as `events.show`), `url.path`, `http.response.status_code`, `http.request.header.content-type`, `http.request.header.x-request-id` (a proxy's request ID, when trusted), `http.request.body.size`, `http.response.body.size` | on |
| `client` | `user_agent.original` (first 256 characters), `client.address` | user agent on, IP anonymized |
| `query` | `url.query`: the query string with only the keys in `query.allow` | no key allowed |
| `session` | `session.hash`: a keyed hash of the session ID, to link requests from the same browser session | on |
| `user` | `user.id`, read at the end of the request so that a login during the request is included | on |
| `auth` | `auth.result`: `success` or `failed`, only on requests that attempt a login | on |
| `locale` | `app.locale` | on |
| `app` | `deployment.environment.name`, `service.version`, `cloud.region`, `host.name`, `process.runtime.version`, `laravel.version` | on |
| `errors` | `error.*`, see [Errors](#errors) | on |
| `performance` | `memory_peak_mb`, `db.query_count`, `db.time_ms`, `cache.hits`, `cache.misses`, `http_client.count`, `http_client.time_ms`, `queue.dispatched` | on |
| `livewire` | `livewire.*` and `filament.action` on Livewire update requests, see [Livewire](livewire.md) | on |

Jobs, commands and scheduled tasks add their own fields:

- Jobs: `job.id`, `job.name`, `job.queue`, `job.attempt`, `job.status` (`processed`, `failed` or `released` for a retry).
- Commands: `console.command`, `console.arguments`, `console.options`, `console.exit_code`.
- Scheduled tasks: `schedule.task`, `schedule.expression`, `schedule.exit_code`.

For `php artisan users:create jane@example.com --admin --password=secret`, the event holds:

```json
{
  "console.command": "users:create",
  "console.arguments": ["email"],
  "console.options": ["--admin", "--password"]
}
```

`console.options` lists only the options that differ from their default. What stays out of these fields, such as argument values, is listed in [Privacy and security](../README.md#privacy-and-security).

> Long-running commands such as `queue:work`, `horizon` or `octane:start` would write one event when they stop, hours later. `console.ignore` in `config/jot.php` skips them. The jobs and requests they run still write their own events.

### Errors

When Laravel reports an exception, the package adds:

- `error.type`: exception class
- `exception.message`: exception message
- `error.code`: exception code
- `error.retriable`: `true` when the exception implements `MarvelousDigital\WideEvents\Contracts\Retriable`
- `error.count`: how many exceptions were reported during the event

How exceptions reach the event: [Errors](../README.md#errors).

### Login example

An anonymous user posts wrong credentials to `/login`. The event holds `auth.result=failed` and no `user.id`.

The user tries again with the right password. The event holds `auth.result=success` and the new `user.id`. The login controller adds nothing: the package reads the user at the end of the request.

### App fields

Some user fields depend on the app's data model: subscription tier, team, role, organization, account age, A/B test cohort. The package cannot guess them, so the app declares them once, in a service provider:

```php
use MarvelousDigital\WideEvents\Facades\Jot;

Jot::user(fn (User $user) => [
    'user.plan' => $user->plan,
    'user.team_id' => $user->current_team_id,
    'user.role' => $user->role,
    'user.account_age_days' => $user->created_at->diffInDays(),
]);
```

The callback runs at the end of each request that has an authenticated user.
