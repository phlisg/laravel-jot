# Fields

A wide event holds two kinds of fields: the ones the app adds with `jot()`, and the ones Jot adds by itself, listed in [Automatic fields](#automatic-fields). The short version is in the README, see [Adding fields](../README.md#adding-fields).

## Contents

- [Methods](#methods)
  - [Facade](#facade)
- [Loose fields](#loose-fields)
  - [Strict mode](#strict-mode)
- [Sections](#sections)
  - [Flattening rules](#flattening-rules)
  - [Attributes](#attributes)
  - [Where sections live](#where-sections-live)
- [Key collisions](#key-collisions)
- [Field conventions](#field-conventions)
- [Automatic fields](#automatic-fields)
  - [Errors](#errors)
  - [User](#user)
  - [Login example](#login-example)
  - [App fields](#app-fields)

Fields never carry over from one request to the next, and each job, command or scheduled task starts with none.

## Methods

Call `jot()` anywhere during a request, job, command or scheduled task. The method names follow Laravel Context:

| Method | What it does |
|---|---|
| `add($key, $value)` | sets a field, or several with an array of `key => value` |
| `addIfMissing($key, $value)` | sets a field only when the app has not set it yet |
| `increment($key, $by = 1)` | adds to a number, starting from 0 |
| `push($key, $value)` | appends to a list, starting from an empty list |

```php
jot()->add('payment.provider', 'stripe');
jot()->add([
    'booking.event_id' => 42,
    'booking.event_slug' => 'jazz-night',
]);
jot()->addIfMissing('payment.currency', 'eur');
jot()->addIfMissing('payment.currency', 'usd');   // ignored, already set
jot()->increment('payment.attempts');
jot()->increment('payment.attempts');
jot()->push('payment.steps', 'intent_created');
jot()->push('payment.steps', 'charged');
```

The event gets:

```json
{
  "payment.provider": "stripe",
  "payment.currency": "eur",
  "payment.attempts": 2,
  "payment.steps": ["intent_created", "charged"],
  "booking.event_id": 42,
  "booking.event_slug": "jazz-night"
}
```

`increment()` leaves a field that holds a non-number unchanged, and `push()` a field that holds a non-list. Neither throws.

### Facade

The `Jot` facade exposes the same methods. Use it when the app already has its own `jot()` function, or in service providers and tests:

```php
use MarvelousDigital\WideEvents\Facades\Jot;

Jot::add('payment.provider', 'stripe');
```

Without an import, the root alias works too: `\Jot::add(...)`. The pair mirrors Laravel's own `logger()` helper and `Log` facade.

> The package declares `jot()` inside `if (! function_exists('jot'))`. If another package or the app defines `jot()` first, that definition wins and no error occurs. Use the facade in that case.

## Loose fields

A loose field is a field added with `add()` and a key, outside any [section](#sections). The key is a string or a backed enum:

```php
jot()->add('feature.new_checkout', true);       // string key
jot()->add(Field::ExperimentVariant, 'b');      // backed enum key, writes experiment.variant
```

A backed enum key is any string-backed enum the app declares, one case per field. The event uses the case value as the key:

```php
enum Field: string
{
    case ExperimentVariant = 'experiment.variant';
    case NewCheckout = 'feature.new_checkout';
}
```

Pick the key type by how long the field lives:

| Key | Use it for | Risk |
|---|---|---|
| String | a one-off field, a field added while debugging, a short experiment | a typo creates a new field, and nothing stops `'42'` in one place and `42` in another |
| Backed enum | fields used in many places with no natural group, such as feature flags or experiments | the enum fixes the name, the value type stays free |
| [Section](#sections) | a group of related fields, such as a payment | none: typed properties fix both the name and the type |

### Strict mode

Strict mode finds string keys, so they can move to an enum or a section. With `JOT_STRICT=true`, the package still writes every string key, and logs a warning for each one on the app's default log channel:

```
[jot] String key "feature.new_checkout" written; declare it in an enum or a section.
```

Turn it on in local and CI (continuous integration) environments, and keep it off in production, where the warnings only add noise. Enum keys and sections never trigger the warning.

String keys cost nothing to add, so they spread. Each one is a field name that lives only in the code that writes it, and a wrong type on one of them breaks queries on that field, see [Field conventions](#field-conventions).

## Sections

A section is a class that groups related fields under one key prefix, for example `payment`. It extends `MarvelousDigital\WideEvents\Section`, and its typed public properties are the fields. `php artisan jot:section PaymentLog` creates an empty one in `app/Jot`.

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

The prefix is the class name in snake case, without a `Log` or `Section` suffix: `PaymentLog` writes under `payment`, `StripePaymentSection` under `stripe_payment`. To pick another prefix, set it on the class:

```php
protected static ?string $prefix = 'payment';
```

`PaymentLog::current()` returns the same instance for the whole request, so any class can fill it. Its return type is `PaymentLog`, so the IDE autocompletes the properties and PHPStan checks them. `jot(PaymentLog::class)` returns the same instance too, and suits a class name known only at runtime: `jot($sectionClass)`.

```php
$payment = PaymentLog::current();
$payment->provider = PaymentProvider::Stripe;
$payment->attempts++;

// Later, in another class, during the same request
PaymentLog::current()->status = PaymentStatus::Declined;
```

The section adds these fields to the event, next to the [automatic fields](#automatic-fields). `intentId` and `latencyMs` are `null`, so they stay out:

```json
{
  "payment.provider": "stripe",
  "payment.status": "declined",
  "payment.attempts": 1
}
```

### Flattening rules

Jot turns each section into flat keys when it builds the event:

| Property | Result |
|---|---|
| Public | written as `prefix.property_name`: `latencyMs` becomes `payment.latency_ms` |
| Protected, private or static | left out |
| `null` or never set | left out |
| Backed enum | its backing value |
| List, such as `['a', 'b']` | one value, a list under one key |
| Associative array | one key per entry, keys in snake case |
| Another `Section` | one key per property, under the property name |
| Marked `#[Hide]` or `#[Key]` | see [Attributes](#attributes) |

For example:

```php
final class CartLog extends Section
{
    public ?CartStatus $status = CartStatus::Open;
    public ?int $totalCents = 4200;
    public ?string $note = null;
    public array $steps = ['added', 'checkout'];
    public array $card = ['brandName' => 'visa', 'last4' => '4242'];
    public ?CouponLog $coupon = null;      // CouponLog has public ?string $code
    protected string $sessionToken = 'x';
}
```

With `$coupon` set to a `CouponLog` whose code is `SPRING`, the event gets:

```json
{
  "cart.status": "open",
  "cart.total_cents": 4200,
  "cart.steps": ["added", "checkout"],
  "cart.card.brand_name": "visa",
  "cart.card.last4": "4242",
  "cart.coupon.code": "SPRING"
}
```

These rules apply to sections only. `jot()->add('booking', ['eventId' => 42])` writes the array as one value under `booking`.

### Attributes

Two attributes change the rules for one property:

| Attribute | Effect | Use it for |
|---|---|---|
| `#[Hide]` | keeps a public property out of the event | a value the code needs but the log must not hold, such as a card token |
| `#[Key('url.path')]` | writes the property under that exact key, with no section prefix | OpenTelemetry names, which rarely match a prefix and a property name |

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
- `StripePayment::current()` returns the added instance. When none was added, it creates one, if the constructor has no required arguments.
- With required constructor arguments, `StripePayment::current()` throws until the instance is added with `jot()->add(new ...)`. The exception names the class and the call to make.
- `jot()` accepts `Section` subclasses only, and throws on any other class.

Section classes can extend each other, for example a `Payment` section with shared fields, extended by `StripePayment` and `PaypalPayment`. A subclass inherits its parent's prefix. Adding both collides, so give the subclass its own `$prefix`, see [Key collisions](#key-collisions).

A Pest architecture test keeps `app/Jot` consistent:

```php
arch('jot sections')
    ->expect('App\Jot')
    ->toExtend(MarvelousDigital\WideEvents\Section::class);
```

## Key collisions

When two writes target the same key, Jot keeps one value and logs a warning. A collision never throws: logging must not break the app, even when `APP_ENV` is set wrong. The README table, [Key collisions](../README.md#key-collisions), sums up each case.

```php
jot()->add('payment.provider', 'paypal');
PaymentLog::current()->provider = PaymentProvider::Stripe;
```

The event holds `"payment.provider": "stripe"`, and the app's default log channel gets a warning that names the key and both writers:

```
[jot] Key "payment.provider" was already added by string, then overwritten by PaymentLog.
```

The details:

| Writes | Kept | Warning |
|---|---|---|
| The same source twice: one string key, one enum key, one section class, the `Jot::user()` callback or `jot()->raise()` | the last value, a normal update | no |
| Two sources, such as a string key then an enum key with the same name | the last value | yes |
| A loose field and a section property | the section, whatever the order: sections are flattened on top of loose fields when the event is built | yes |
| An app field and an [automatic field](#automatic-fields) | the automatic field | yes |
| A value and child keys under one name: `order` next to `order.id` | the child keys, the value is dropped | yes |
| Two section classes with one prefix | they share one slot: adding one replaces the other. `jot(Other::class)` returns the instance already there, `Other::current()` throws `JotSectionPrefixConflict` unless that instance is an `Other` | no |

An automatic field wins only when it has a value. With a user loaded during the request, `jot()->add('user.id', 5)` gives way to that user's ID. With no user, `user.id` stays 5.

A value and child keys cannot coexist because the nested output shape turns `order.id` into `{"order": {"id": 9}}`, which leaves no place for a value of `order`, see [Output shapes](outputs.md#output-shapes).

## Field conventions

Log backends such as Elasticsearch fix a field's type the first time they see it. A later value of another type causes a mapping conflict, and queries on that field fail. These rules keep every field queryable:

| Rule | Example |
|---|---|
| One type per key, forever. Typed section properties enforce this in PHP. | `payment.attempts` is always an integer |
| Namespaced keys | `payment.provider` over a bare `provider` |
| Singular prefixes | `payment`, `booking` |
| Units in the name | `latency_ms`, `total_cents` |
| Backed enums for any field used to filter or group | status, provider, error type, plan tier |
| IDs in values, never in keys | `user.id = 42`. A key such as `user_42.plan` creates one field per user. |
| Domain words for prefixes | a booking app uses `booking.event_id`: a bare `event.*` prefix reads like the wide event itself or a Laravel event |

## Automatic fields

Jot adds these fields with no code in the app. Each group has a switch under `fields` in `config/jot.php`, see [Configuration](configuration.md). A field with no value, such as `user.id` on an anonymous request, stays out of the event.

Field names follow the OpenTelemetry semantic conventions where one exists. Fields with no OpenTelemetry equivalent, such as `session.hash` or `laravel.version`, keep a Jot name.

Always on, in every event:

| Field | Value |
|---|---|
| `trace_id` | shared by a request and every job and command it starts, so one query finds them all. New at the start of each trace. The `X-Request-Id` response header returns it (`trace.header` in `config/jot.php` renames the header). |
| `span_id` | this event's own ID |
| `parent_span_id` | the `span_id` of the request, job or command that started this one. Absent on the first event of a trace. |
| `timestamp` | start time, in ISO 8601 format |
| `entrypoint` | where the unit of work entered the app: `http`, `queue`, `console` or `schedule` |
| `origin` | the `entrypoint` of the first event in the trace. A job dispatched by a scheduled task has `entrypoint=queue` and `origin=schedule`. |
| `duration_ms` | time from start to emit |

The IDs follow [W3C Trace Context](https://www.w3.org/TR/trace-context/): 32 hexadecimal characters for `trace_id`, 16 for the span IDs.

`traceparent` is the W3C header that carries a trace ID from one service to the next. With `trace.trust_incoming` on, a `traceparent` header on the request sets `trace_id` and `parent_span_id`, and an incoming `X-Request-Id` goes to `http.request.header.x-request-id`. Turn it on only behind a proxy that sets or strips both headers: otherwise any client can pick the trace ID of its own requests.

On HTTP requests, by group:

| Group | Fields | Default |
|---|---|---|
| `http` | `http.request.method`, `http.route` (the route name, such as `events.show`), `url.path`, `http.response.status_code`, `http.request.header.content-type`, `http.request.header.x-request-id` (a proxy's request ID, when trusted), `http.request.body.size`, `http.response.body.size` | on |
| `client` | `user_agent.original` (first 256 characters), `client.address` | user agent on, IP anonymized |
| `query` | `url.query`: the query string with only the keys in `query.allow` | no key allowed |
| `session` | `session.hash`: a keyed hash of the session ID, to link requests from the same browser session | on |
| `user` | `user.id`, and `user.guard`: the name of the auth guard, such as `web`. See [User](#user). | on |
| `auth` | `auth.result`: `success` or `failed`, only on requests that attempt a login. A visitor signed back in by the remember-me cookie gets no `auth.result`. | on |
| `locale` | `app.locale` | on |
| `livewire` | `livewire.*` and `filament.action` on Livewire update requests, see [Livewire](livewire.md) | on |

On every event, by group:

| Group | Fields | Default |
|---|---|---|
| `app` | `deployment.environment.name`, `service.version`, `cloud.region`, `host.name`, `process.runtime.version`, `laravel.version` | on |
| `errors` | `error.*`, see [Errors](#errors) | on |
| `performance` | `memory_peak_mb`, `db.query_count`, `db.time_ms`, `cache.hits`, `cache.misses`, `http_client.count`, `http_client.time_ms`, `queue.dispatched` | on |

Jobs, commands and scheduled tasks add their own fields:

| Unit of work | Fields |
|---|---|
| Job | `job.id`, `job.name`, `job.queue`, `job.attempt`, `job.status` (`processed`, `failed`, or `released` for a retry) |
| Command | `console.command`, `console.arguments`, `console.options`, `console.exit_code` |
| Scheduled task | `schedule.task`, `schedule.expression`, `schedule.exit_code` |

`console.arguments` and `console.options` hold names only, for the arguments and options that differ from their default. For `php artisan users:create jane@example.com --admin --password=secret`, where `jane@example.com` fills the `email` argument, the event holds:

```json
{
  "console.command": "users:create",
  "console.arguments": ["email"],
  "console.options": ["--admin", "--password"],
  "console.exit_code": 0
}
```

What else stays out of these fields is listed in [Privacy and security](../README.md#privacy-and-security).

> Long-running commands such as `queue:work`, `horizon` or `octane:start` would write one event when they stop, hours later. `console.ignore` in `config/jot.php` skips them. The jobs and requests they run still write their own events.

### Errors

When Laravel reports an exception, Jot adds:

| Field | Value |
|---|---|
| `error.type` | exception class |
| `exception.message` | exception message, cut to 2000 characters |
| `error.code` | exception code |
| `error.retriable` | `true` when the exception implements `MarvelousDigital\WideEvents\Contracts\Retriable` |
| `error.count` | how many exceptions were reported during the event |

With several exceptions, the other fields describe the last one. How exceptions reach the event: [Errors](../README.md#errors).

### User

`user.id` and `user.guard` hold the user that the app loaded during the request, from any auth guard. For a request to carry `user.id`, the app must check the user in it, for example with the `auth` middleware or `auth()->user()`.

| Source | What Jot records |
|---|---|
| Laravel's `Authenticated` or `Login` event, from any guard | that guard and user. `Logout` or `CurrentDeviceLogout` on the same guard clears them. |
| The default guard, at the end of the request | its user, if the guard already holds one. This user wins over one from an event. |

Jot never loads the user itself. A public page that never checks the user writes no `user.id`, even for a signed-in visitor. In return, Jot adds no SQL query and never signs a visitor in from the remember-me cookie after the response.

Token guards, such as Sanctum tokens, `RequestGuard` or `TokenGuard`, fire no auth events. Jot records their user only when the guard is the default one and the app already loaded the user.

### Login example

1. An anonymous user posts wrong credentials to `/login`. The event holds `auth.result=failed` and no `user.id`.
2. The user tries again with the right password. The event holds `auth.result=success` and the new `user.id`.

The login controller adds nothing: Jot listens to Laravel's login events.

### App fields

Some user fields depend on the app's data model: subscription tier, team, role, organization, account age, A/B test cohort. Declare them once, in a service provider:

```php
use MarvelousDigital\WideEvents\Facades\Jot;

Jot::user(fn (User $user) => [
    'user.plan' => $user->plan,
    'user.team_id' => $user->current_team_id,
    'user.role' => $user->role,
    'user.account_age_days' => $user->created_at->diffInDays(),
]);
```

The callback runs at the end of each request that has a `user.id`, with the same user, when the `user` group is on. A request where the app never loaded a user skips it, see [User](#user).
