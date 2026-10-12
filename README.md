# Laravel Wide Events

Laravel Wide Events writes one structured log event ("wide event") per HTTP request, queued job, Artisan command or scheduled task, with a typed schema that keeps the fields queryable.

## Contents

- [Why](#why)
- [Requirements](#requirements)
- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Sections](#sections)
- [Loose fields](#loose-fields)
- [Field conventions](#field-conventions)
- [Automatic fields](#automatic-fields)
- [Example: paid event booking](#example-paid-event-booking)
- [Errors](#errors)
- [Sampling](#sampling)
- [Configuration](#configuration)
- [Sentry and GlitchTip](#sentry-and-glitchtip)
- [Output shapes](#output-shapes)
- [Testing](#testing)
- [Roadmap](#roadmap)
- [Versioning](#versioning)
- [Contributing](#contributing)

## Why

Classic logs scatter many short lines across a request. Finding everything about one failed checkout means searching for strings and stitching lines together by hand.

A wide event (also called a canonical log line) is a single log entry that holds all the context for one unit of work: request ID, HTTP status, duration, user, business data, errors. The code fills the event as it runs and the package writes it once, at the end. The idea comes from [loggingsucks.com](https://loggingsucks.com/), an article on why wide events beat line-by-line logs.

Laravel Wide Events applies the idea with one verb: code anywhere in the request jots fields down with `jot()`, and the package writes them as one wide event.

## Requirements

- PHP 8.3
- Laravel 11 or later, which provides the `Context` facade (the Laravel feature that shares data across a request and its queued jobs)

## Features

- Zero setup: install the package and every request, job, command and scheduled task writes a wide event.
- `jot()` global helper, with a `Jot` facade as a fallback.
- Request ID and trace ID on every request, propagated to queued jobs through Laravel Context.
- Automatic fields: user, hashed session, login outcome, HTTP details, errors, duration, database usage.
- Typed section classes that define the log schema.
- Backed enums for fields with a fixed set of values.
- Tail sampling: keep every error and slow request, sample the rest.
- Strict mode that warns on fields outside the schema.
- Sentry and GlitchTip: issues tagged with the request ID, events in Sentry Logs.

## Installation

```sh
composer require marvelous-digital/laravel-wide-events
```

The repository is private: add it to the app's Composer `repositories` first, see [Install from another project](docs/development.md#install-from-another-project).

Nothing else is required. The package registers its helper, service provider, global middleware and event listeners itself, and its defaults apply until the app publishes `config/jot.php`.

To change a default, publish the config file. It lists every feature with its default value:

```sh
php artisan jot:install
```

`jot:install` also runs `php artisan jot:env`, which adds the Jot keys missing from `.env.example` and `.env`, with their default values. It keeps existing keys, including commented-out ones, so it is safe to run again after an upgrade.

`php artisan vendor:publish --tag=jot-config` publishes the config file alone.

## Usage

Application code adds fields with the `jot()` helper. The method names follow Laravel Context, so they read the same:

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
  "request_id": "0b6f1c1e-5d0a-4a57-9a43-3f6f0f5f2a10",
  "trace_id": "0b6f1c1e-5d0a-4a57-9a43-3f6f0f5f2a10",
  "http.request.method": "POST",
  "http.route": "checkout.store",
  "http.response.status_code": 402,
  "duration_ms": 412,
  "payment.provider": "stripe",
  "payment.status": "declined",
  "payment.attempts": 1
}
```

Flattening rules:

- Exported properties: public ones only. Protected and private properties stay out of the event.
- Keys: the prefix, a dot, then the property name in snake case. `latencyMs` becomes `payment.latency_ms`.
- Backed enums: written as their backing value.
- `null` properties: skipped.
- Lists: written as one value. `['intent_created', 'charged']` stays a list under one key.
- Nested `Section` properties: flattened into deeper keys, for example `cart.coupon.code`.

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

Section classes can extend each other, for example a `Payment` section with shared fields, extended by `StripePayment` and `PaypalPayment`. A subclass inherits its parent's `$prefix`, and then collides with it, see "Key collisions" below.

A Pest architecture test keeps `app/Jot` consistent:

```php
arch('jot sections')
    ->expect('App\Jot')
    ->toExtend(MarvelousDigital\WideEvents\Section::class);
```

### Key collisions

String keys and sections can target the same key. The last write wins, and the package never throws during a request: logging must not break the app, even when `APP_ENV` is set wrong.

- The same string key added twice: a normal update. `payment.status` often changes during a request.
- The same section class added twice: a normal update, the new instance replaces the old one.
- A collision: a string key already written by a section, two section classes with the same prefix, or a value and child keys under one name (`payment` next to `payment.provider`).

On a collision, the package keeps the last write and logs a warning on the app's default log channel. The warning names the key and what added it first:

```
[jot] Key "payment.provider" was already added by App\Jot\StripePayment, then overwritten by a string key.
```

Automatic fields always win over app fields with the same key.

## Loose fields

A one-off field with no section uses `add` with a string or a backed enum key:

```php
jot()->add('feature.new_checkout', true);
jot()->add(Field::ExperimentVariant, 'b');
```

Strict mode is off by default. With strict mode on, the package still writes string keys, and logs a warning for each one on the app's default log channel. Enum keys and sections never trigger the warning.

## Field conventions

Log backends such as Elasticsearch fix a field's type the first time they see it. A later value of another type causes a mapping conflict, and queries on that field fail. These rules keep every field queryable:

- One type per key, forever. Typed section properties enforce this in PHP.
- Namespaced keys: `payment.provider`, never `provider`.
- Singular prefixes: `payment`, `booking`.
- Units in the name: `latency_ms`, `total_cents`.
- Backed enums for any field used to filter or group, such as status, provider, error type, or plan tier.
- IDs go in values, never in keys: `user.id = 42`, never `user_42.plan`.
- Domain words for prefixes. A booking app uses `booking.event_id`: a bare `event.*` prefix reads like the wide event itself or a Laravel event.

## Automatic fields

The package adds these fields with no code in the app. Each group has a switch under `fields` in `config/jot.php`, see "Configuration" below.

Field names follow the OpenTelemetry semantic conventions where one exists. Fields with no OpenTelemetry equivalent, such as `session.hash` or `laravel.version`, keep a Jot name.

Always on:

- `request_id`: the `X-Request-Id` header when trusted, otherwise a new UUID (universally unique identifier). Jobs keep the `request_id` of the request, command or task that started the chain.
- `trace_id`: an incoming trace ID when trusted, otherwise the `request_id`. Queued jobs keep it, so one trace links a request to every job it dispatched.
- `parent_id`: on jobs, the `request_id` of the dispatching request or the `job.id` of the dispatching job.
- `timestamp`: start time, in ISO 8601 format.
- `entrypoint`: where the unit of work entered the app: `http`, `queue`, `console` or `schedule`.
- `origin`: the `entrypoint` of the first event in the trace. A job dispatched by a scheduled task has `entrypoint=queue` and `origin=schedule`.
- `duration_ms`: time from start to emit.

By group:

| Group | Fields | Default |
|---|---|---|
| `http` | `http.request.method`, `http.route` (the route name, such as `events.show`), `url.path`, `http.response.status_code`, `http.request.header.content-type`, `http.request.body.size`, `http.response.body.size` | on |
| `client` | `user_agent.original` (first 256 characters), `client.address` | user agent on, IP anonymized |
| `query` | `url.query`: the query string with only the keys in `query.allow` | no key allowed |
| `session` | `session.hash`: a keyed hash of the session ID, to link requests from the same browser session | on |
| `user` | `user.id`, read at the end of the request so that a login during the request is included | on |
| `auth` | `auth.result`: `success` or `failed`, only on requests that attempt a login | on |
| `locale` | `app.locale` | on |
| `app` | `deployment.environment.name`, `service.version`, `cloud.region`, `host.name`, `process.runtime.version`, `laravel.version` | on |
| `errors` | `error.*`, see "Errors" below | on |
| `performance` | `memory_peak_mb`, `db.query_count`, `db.time_ms`, `cache.hits`, `cache.misses`, `http_client.count`, `http_client.time_ms`, `queue.dispatched` | on |

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

> Security: the package logs argument and option names, never their values. Values can carry secrets, such as the password above. `console.options` lists only the options that differ from their default.

> Security: the package never logs the raw session ID. Anyone who reads the logs could use it to take over a live session. `session.hash` is an HMAC (keyed hash) of the ID with the app key, cut to 16 characters.

> Security: `url.path` holds no query string. Password reset tokens and signed URL signatures travel in the query string. The `query` group logs only the keys listed in `query.allow`.

> Privacy: an IP address is personal data under the GDPR (General Data Protection Regulation). The default `anonymized` mode zeroes the last IPv4 byte (`192.168.1.42` becomes `192.168.1.0`) or the last 80 bits of an IPv6 address. Behind a load balancer, configure Laravel's trusted proxies, otherwise every request logs the load balancer's IP.

> Privacy: a failed login logs `auth.result` only. The email or username that was tried stays out of the event.

> Long-running commands such as `queue:work`, `horizon` or `octane:start` would write one event when they stop, hours later. `console.ignore` in `config/jot.php` skips them. The jobs and requests they run still write their own events.

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

## Example: paid event booking

A user books a paid event: they open the event page, fill in their details, and pay with Stripe. Each step is a separate HTTP request, so each step writes its own wide event.

| Request | Fields added by the app |
|---|---|
| `GET /events/{event}` | `booking.event_id`, `booking.event_slug` |
| `POST /events/{event}/details` | `booking.event_id` |
| `POST /events/{event}/pay` | `booking.event_id`, `payment.provider=stripe`, `payment.intent_id`, `payment.status` |
| `POST /stripe/webhook` | `payment.intent_id`, `payment.status` |

The payment step in code:

```php
jot()->add('booking.event_id', $event->id);

$payment = jot(PaymentLog::class);
$payment->provider = PaymentProvider::Stripe;
$payment->intentId = $intent->id;
$payment->status = PaymentStatus::Approved;
```

Each request adds what it knows, usually from the route. To follow one user through the funnel, query the log backend on `booking.event_id` plus `session.hash` or `user.id`.

> The Stripe webhook comes from Stripe's servers, so it has no session and no user. Store the `trace_id` in the payment intent `metadata`, and add it back in the webhook handler to link both events.

## Errors

When Laravel reports an exception during a request, job, command or scheduled task, the package adds:

- `error.type`: exception class
- `exception.message`: exception message
- `error.code`: exception code
- `error.retriable`: `true` when the exception implements `MarvelousDigital\WideEvents\Contracts\Retriable`

Exceptions Laravel does not report, such as validation errors, add nothing.

## Sampling

The package decides at emit time whether to write the event (tail sampling). It keeps:

- Every event with `http.response.status_code` of 500 or more, a failed job, or an `error.type`.
- Every event slower than `sampling.slow_ms`.
- Every event where a custom rule returns `true`, for example enterprise customers.
- A random `sampling.rate` share of all other events.

Register custom rules in a service provider:

```php
Jot::keepWhen(fn (array $event) => ($event['user.plan'] ?? null) === 'enterprise');
```

## Configuration

Every setting lives in `config/jot.php`. The file lists each feature with its default, so the app turns features on or off in one place. Publish it with `php artisan jot:install`.

```php
use MarvelousDigital\WideEvents\Enums\OutputShape;

return [

    'enabled' => env('JOT_ENABLED', true),

    // One entry per destination. Each output writes the same event in its own shape.
    'outputs' => [
        // channel null: a daily file in storage/logs with one JSON object per line.
        // level: optional, the lowest event level this output writes.
        ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
    ],

    // Added to the outputs on its own when sentry/sentry-laravel is installed with SENTRY_ENABLE_LOGS=true.
    // Events below this level stay out of Sentry: error, warning, notice or info.
    'sentry' => [
        'enabled' => env('JOT_SENTRY', true),
        'level' => env('JOT_SENTRY_LEVEL', 'info'),
    ],

    'request_id' => [
        'header' => 'X-Request-Id',
        // Enable only behind a proxy that sets or strips this header.
        'trust_incoming' => env('JOT_TRUST_INCOMING_HEADER', false),
    ],

    'app' => [
        'version' => env('APP_VERSION'),
        'region' => env('JOT_REGION'),
    ],

    'fields' => [
        'http' => true,

        'client' => [
            'user_agent' => true,
            // false, 'anonymized' or 'full'. An IP address is personal data under the GDPR.
            'ip' => 'anonymized',
        ],

        // Query keys to log, wildcards allowed, e.g. ['utm_*']. Never list token keys.
        'query' => [
            'allow' => [],
        ],

        'session' => true,
        'user' => true,
        'auth' => true,
        'locale' => true,
        'app' => true,
        'errors' => true,

        'performance' => [
            'memory' => true,
            'db' => true,
            'cache' => true,
            'http_client' => true,
            'queue' => true,
        ],

        // Ignored when laravel/pennant is not installed.
        'pennant' => true,
    ],

    'queue' => [
        'enabled' => true,
    ],

    'console' => [
        'enabled' => true,
        'schedule' => true,
        // Long-running commands, wildcards allowed.
        'ignore' => [
            'queue:work', 'queue:listen', 'horizon*', 'octane:*', 'reverb:start',
            'schedule:run', 'schedule:work', 'pail', 'tinker',
        ],
    ],

    // Logs a warning for each string key written outside a section.
    'strict' => env('JOT_STRICT', false),

    'sampling' => [
        // Share of normal events to keep, from 0.0 to 1.0. Errors and slow events are always kept.
        'rate' => env('JOT_SAMPLE_RATE', 1.0),
        'slow_ms' => 1000,
    ],

];
```

Settings that need a decision per app:

- `outputs`: each entry pairs a log channel from `config/logging.php` with a shape, see "Output shapes" below. The channel's formatter encodes the event (JSON, logfmt, and so on). Use a JSON formatter so the log backend can index each field. An optional `level` key keeps only events at that level or above, see "Sentry and GlitchTip" below for the levels.
- `request_id.trust_incoming`: off by default, so that clients cannot choose their own request ID or trace ID. Turn it on behind a proxy or load balancer that sets the header. A trusted W3C `traceparent` header also sets the `trace_id`.
- `app.version`: value of the `service.version` field, a git commit hash or release tag, to compare errors between deploys.
- `fields.client.ip`: `anonymized` by default. `full` needs a legal basis under the GDPR.
- `fields.query.allow`: empty by default. Add marketing keys such as `utm_*`, never tokens or signatures.
- `sampling.rate`: lower it when log volume costs too much. Errors and slow events stay at 100%.

## Sentry and GlitchTip

With the Sentry SDK for Laravel (`sentry/sentry-laravel`) installed, Jot works with Sentry and needs no setup:

- Every Sentry issue gets the tags `request_id` and `trace_id`, which point to its wide event in the logs.
- With Sentry Logs on, each event also goes to Sentry Logs, with every field as a searchable attribute.

GlitchTip, an open source alternative to Sentry, accepts the same SDK and its logs.

To turn Sentry Logs on, set in `.env`:

```sh
SENTRY_ENABLE_LOGS=true
JOT_SENTRY_LEVEL=warning
```

Each event gets a log level, from its fields:

| Level | Events |
|---|---|
| `error` | an `error.type`, a status of 500 or more, a failed job |
| `warning` | a 4xx status, a command or scheduled task with a non-zero exit code |
| `notice` | slower than `sampling.slow_ms` |
| `info` | every other event |

`JOT_SENTRY_LEVEL` sets the lowest level that goes to Sentry, `info` by default. Set it per environment, for example `warning` in production and `info` in staging. `JOT_SENTRY=false` turns off both the logs and the tags.

> Only sampled events reach Sentry, see "Sampling" above.

> The SDK's own `SENTRY_LOG_LEVEL` (default: `LOG_LEVEL`) filters the same channel for every log line of the app. Keep it at or below `JOT_SENTRY_LEVEL`.

> Jot creates no Sentry issues. The SDK already reports each exception, and a second issue would duplicate it.

## Output shapes

Each entry in `outputs` pairs a log channel with a shape. The shape sets the structure of the event array. The channel's formatter then encodes it as JSON, logfmt or another format.

- `OutputShape::Flat`: one level with dotted keys, `{"http.request.method": "POST"}`. The default. Suits Datadog, Loki and most search backends.
- `OutputShape::Nested`: dotted keys become nested arrays, `{"http": {"request": {"method": "POST"}}}`. XML formatters need this shape.
- `OutputShape::Otel`: the OpenTelemetry (OTel) log data model. Service fields such as `service.version` go to `Resource`, the other fields go to `Attributes`, and `trace_id` goes to `TraceId`. An OpenTelemetry Collector can read a log file in this shape.

> OTel marks some names as incubating, which means a later OTel release can still rename them. Jot uses these incubating names: `host.name`, `user.id`, `http.request.body.size`, `http.response.body.size` and `process.runtime.version`.

## Testing

`Jot::fake()` records events instead of writing them. Sampling still applies.

```php
use MarvelousDigital\WideEvents\Facades\Jot;

Jot::fake();

$this->post('/checkout');

Jot::assertEmitted(fn (array $event) => $event['payment.status'] === 'declined');
```

## Roadmap

- `pennant` group: `feature.<name>` for each Laravel Pennant feature flag checked during a request.
- Export through the OpenTelemetry SDK, detected from `OpenTelemetry\API\Globals::loggerProvider()`, with the active span's trace ID as `trace_id`.

## Versioning

The package follows semantic versioning and starts at `0.x`. Until `1.0.0`, a minor release (`0.1` to `0.2`) can contain breaking changes. Patch releases (`0.1.0` to `0.1.1`) never do.

- Composer's caret constraint on a `0.x` version stays within one minor release: `composer require marvelous-digital/laravel-wide-events:^0.1` installs `0.1.*` only.
- `CHANGELOG.md` lists the breaking changes of each minor release.

`1.0.0` comes once the public API (`jot()`, the `Jot` facade, `Section`, `config/jot.php`) is stable.

Release steps and release notes: see [Releases](docs/development.md#releases). To install a version in another project: see [Install from another project](docs/development.md#install-from-another-project).

## Contributing

- [docs/architecture.md](docs/architecture.md): how the package collects and writes events, request and job lifecycle, open questions.
- [docs/development.md](docs/development.md): Docker setup, tests, static analysis, PHP versions, the workbench app that runs demo scenarios, and releases.
