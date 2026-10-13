# Laravel Wide Events

Laravel Wide Events writes one structured log event ("wide event") per HTTP request, queued job, Artisan command or scheduled task, with a typed schema that keeps the fields queryable.

## Contents

- [Why](#why)
- [Requirements](#requirements)
- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
  - [Adding fields](#adding-fields)
  - [Facade](#facade)
  - [Reading events](#reading-events)
- [Configuration](#configuration)
- [Sections](#sections)
- [Loose fields](#loose-fields)
- [Field conventions](#field-conventions)
- [Automatic fields](#automatic-fields)
- [Livewire](#livewire)
- [Example: paid event booking](#example-paid-event-booking)
- [Errors](#errors)
- [Sampling](#sampling)
- [Sentry and GlitchTip](#sentry-and-glitchtip)
- [Output shapes](#output-shapes)
- [Testing](#testing)
- [Roadmap](#roadmap)
- [Versioning](#versioning)
- [Contributing](#contributing)

## Why

Classic logs scatter many short lines across a request. Finding everything about one failed checkout means searching for strings and stitching lines together by hand.

A wide event (also called a canonical log line) is a single log entry that holds all the context for one unit of work: trace ID, HTTP status, duration, user, business data, errors. The code fills the event as it runs and the package writes it once, at the end. The idea comes from [loggingsucks.com](https://loggingsucks.com/), an article on why wide events beat line-by-line logs.

Laravel Wide Events applies the idea with one verb: code anywhere in the request jots fields down with `jot()`, and the package writes them as one wide event.

## Requirements

- PHP 8.3 or later
- Laravel 11, 12 or 13. Jot relies on the `Context` facade, added in Laravel 11, which shares data across a request and its queued jobs.

## Features

- Zero setup: install the package and every request, job, command and scheduled task writes a wide event.
- `jot()` global helper, with a `Jot` facade as a fallback.
- Trace ID on every event, shared by a request and the jobs and commands it starts, in the [W3C Trace Context](https://www.w3.org/TR/trace-context/) format.
- Automatic fields: user, hashed session, login outcome, HTTP details, errors, duration, database usage.
- Typed section classes that define the log schema.
- Backed enums for fields with a fixed set of values.
- Tail sampling: keep every error and slow request, sample the rest.
- Strict mode that warns on fields outside the schema.
- Sentry and GlitchTip: issues tagged with the trace ID, events in Sentry Logs.
- `jot:query`: reads events back from the log files, by trace, by filter or in an interactive table.
- Livewire and Filament: each Livewire update records its components, calls and failed validations.

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

### Adding fields

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

### Reading events

`php artisan jot:query` reads events back from the log files, so nobody has to find and search them by hand:

- `jot:query`: the most recent events, with filters.
- `jot:query <trace_id>`: one trace.
- `jot:query <span_id>`: one event, with every field.

At a terminal, it opens an interactive list. Piped, in CI (continuous integration), with `-n` (`--no-interaction`) or with `--json`, it prints plain lines.

It reads the outputs that write files: the default Jot file and any `single` or `daily` channel. For other outputs, such as `stderr` or Sentry Logs, it prints where else to look.

For a live stream of all log messages, Jot events included, run `php artisan pail`.

#### Recent events

Without an ID, `jot:query` lists the most recent events, oldest first, one line per event: time, trace ID, then a summary of the event.

```sh
php artisan jot:query                                   # last 20 events from today's files
php artisan jot:query --limit=50 --since=15m
php artisan jot:query --errors                          # error.type set, 5xx status or failed job
php artisan jot:query --slow                            # slower than jot.sampling.slow_ms
php artisan jot:query --where=http.route=events.* --where=user.id=42
php artisan jot:query --where=entrypoint=queue,schedule  # jobs and scheduled tasks
php artisan jot:query --follow                          # keep printing new events, Ctrl+C to stop
php artisan jot:query --json                            # one JSON line per event
```

```
06:47:00  555ee94c04b56b2e7b91a58635e1a1f8  http  d7a93d989a9b145b  GET events.show 200  5ms
06:47:00  7b096114f4407571aa14af9f01e5ef9d  http  13cbb92ae552c95a  POST events.details 200  0ms
06:47:00  f682e78131053797dc9bb24718dd5ee7  http  f753e791229612e7  POST events.pay 200  1ms
06:47:00  e3a6df5d662b366fad87a7969a4ff985  http  c6b2d9316c7f6888  POST stripe.webhook 204  1ms
Run jot:query <trace_id> for a trace, or <span_id> for every field.
```

- `--where`: `key=value` on the flat field names, with `*` wildcards. Commas list alternatives: `--where=entrypoint=http,queue`. Repeat `--where` to require several fields.
- `--since`: a number and a unit (`s`, `m`, `h` or `d`), such as `15m`.
- `--days=1`: how many days of files to read. `--since` reads further back when it needs to.
- `--follow`: prints new events as they are written, as plain lines, even at a terminal.

These options work without an ID only. With an ID, `jot:query` refuses all of them except `--days` and `--json`.

#### Browsing

At a terminal, the recent events open in a table. Arrow keys move, `/` searches (trace and span IDs match too), `r` reloads the events, and Enter prints every field of the selected event:

```sh
php artisan jot:query --since=1h
```

```
┌ Recent events ──────────────────────────────────────────────────────────┐
│ / Search                                                                │
├──────────┬───────┬───────────────────────┬────┬─────────────────────────┤
│ Time     │ Type  │ What                  │ ms │ Error                   │
├──────────┼───────┼───────────────────────┼────┼─────────────────────────┤
│ 06:51:12 │ queue │ ChargeCard failed     │ 7  │ RuntimeException: Card… │
│ 06:51:12 │ http  │ POST jobs.failing 200 │ 13 │                         │
└──────────┴───────┴───────────────────────┴────┴─────────────────────────┘
```

- The newest event is at the top.
- After the fields, pick Back to the list, Show the trace or Quit. Show the trace lists the events of the trace as a tree, see [One trace](#one-trace). Enter on any of them prints its fields.
- The table takes the same filters as the plain list.

#### One trace

With a trace ID, `jot:query` prints the events of the trace as a tree. `parent_span_id` places each event under the one that started it:

```sh
php artisan jot:query 4bf92f3577b34da6a3ce929d0e0e4736
```

```
Trace 4bf92f3577b34da6a3ce929d0e0e4736, 2 event(s)
http  f56220033574661e  POST checkout.store 200  412ms
└─ queue  69f6835287391bf7  ChargeCard failed  10ms  RuntimeException: Card network timeout
```

- Trace ID (32 hex characters): prints the whole trace. The `X-Request-Id` response header holds it.
- Span ID (16 hex characters): prints the same tree, then every field of that event.
- `--days=7`: how many days of files to read.
- `--json`: one JSON line per event.

At a terminal, a trace with several events opens as a list laid out as the tree. Enter on an event prints every field, then offers Back to the trace or Quit. A span ID, or a trace with a single event, prints the tree as above.

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
        // Optional URL to the event in your log backend, shown on each Sentry issue. Placeholders: {trace_id}, {span_id}.
        'link' => env('JOT_SENTRY_LINK'),
    ],

    'trace' => [
        // Response header that returns the trace ID.
        'header' => 'X-Request-Id',
        // Enable only behind a proxy that sets or strips traceparent and this header.
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
        // Components, calls and validation errors of Livewire update requests, when Livewire is installed.
        'livewire' => true,

        'performance' => [
            'memory' => true,
            'db' => true,
            'cache' => true,
            'http_client' => true,
            'queue' => true,
        ],
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
            'schedule:run', 'schedule:work', 'pail', 'tinker', 'jot:*', 'help', 'list',
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

- `outputs`: each entry pairs a log channel from `config/logging.php` with a shape, see [Output shapes](#output-shapes). The channel's formatter encodes the event (JSON, logfmt, and so on). Use a JSON formatter so the log backend can index each field. An optional `level` key keeps only events at that level or above, see [Sentry and GlitchTip](#sentry-and-glitchtip) for the levels.
- `trace.trust_incoming`: off by default, so that clients cannot choose their own trace ID. Turn it on behind a proxy or load balancer that sets or strips the `traceparent` and `X-Request-Id` headers. A trusted W3C `traceparent` header sets `trace_id` and `parent_span_id`. A trusted `X-Request-Id` header goes to `http.request.header.x-request-id`.
- `app.version`: value of the `service.version` field, a git commit hash or release tag, to compare errors between deploys.
- `fields.client.ip`: `anonymized` by default. `full` needs a legal basis under the GDPR.
- `fields.query.allow`: empty by default. Add marketing keys such as `utm_*`, never tokens or signatures.
- `sampling.rate`: lower it when log volume costs too much. Errors and slow events stay at 100%.

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

Section classes can extend each other, for example a `Payment` section with shared fields, extended by `StripePayment` and `PaypalPayment`. A subclass inherits its parent's `$prefix`, and then collides with it, see [Key collisions](#key-collisions).

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

The fields the package adds itself, listed in [Automatic fields](#automatic-fields), always win over app fields with the same key. With a user logged in, `jot()->add('user.id', 5)` gives way to that user's ID, with a warning.

## Loose fields

A loose field has a string or enum key and belongs to no section. The app adds a field in one of three ways, from the quickest to the safest:

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

String keys cost nothing to add, so they spread. Each one is a field name that lives only in the code that writes it, and a wrong type on one of them breaks queries on that field, see [Field conventions](#field-conventions).

Strict mode finds them. With `JOT_STRICT=true`, the package still writes every string key, and logs a warning for each one on the app's default log channel:

```
[jot] String key "feature.new_checkout" written; declare it in an enum or a section.
```

Enum keys and sections never trigger the warning. Turn strict mode on in local and CI environments to catch string keys before they reach production. Keep it off in production, where the warnings only add noise.

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

The package adds these fields with no code in the app. Each group has a switch under `fields` in `config/jot.php`, see [Configuration](#configuration).

Field names follow the OpenTelemetry semantic conventions where one exists. Fields with no OpenTelemetry equivalent, such as `session.hash` or `laravel.version`, keep a Jot name.

Always on:

- `trace_id`: shared by a request and every job and command it starts, so one query finds them all. New at the start of each trace, unless a trusted `traceparent` header brings one. The `X-Request-Id` response header returns it.
- `span_id`: this event's own ID.
- `parent_span_id`: the `span_id` of the request, job or command that started this one. Absent on the first event of a trace.
- `timestamp`: start time, in ISO 8601 format.
- `entrypoint`: where the unit of work entered the app: `http`, `queue`, `console` or `schedule`.
- `origin`: the `entrypoint` of the first event in the trace. A job dispatched by a scheduled task has `entrypoint=queue` and `origin=schedule`.
- `duration_ms`: time from start to emit.

The IDs follow W3C Trace Context: 32 hexadecimal characters for `trace_id`, 16 for the span IDs.

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

## Livewire

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

### Polling

A component with `wire:poll` sends a request every few seconds while the page stays open, and each request writes an event. A `dropWhen` rule keeps them out of the logs, see [Sampling](#sampling). On Livewire 3 or 4, match the polled method:

```php
Jot::dropWhen(fn (array $event) => ($event['livewire.calls'] ?? null) === ['registration-form::checkStatus']);
```

On Livewire 4 only, `livewire.poll` marks every poll:

```php
Jot::dropWhen(fn (array $event) => $event['livewire.poll'] ?? false);
```

Errors and slow polls are still kept.

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

> The Stripe webhook comes from Stripe's servers, so it has no session and no user. Store the `trace_id` in the payment intent `metadata`. In the webhook handler, add it as `payment.trace_id`: the webhook event has its own `trace_id`, and automatic fields always win.

## Errors

When Laravel reports an exception during a request, job, command or scheduled task, the package adds:

- `error.type`: exception class
- `exception.message`: exception message
- `error.code`: exception code
- `error.retriable`: `true` when the exception implements `MarvelousDigital\WideEvents\Contracts\Retriable`
- `error.count`: how many exceptions were reported during the event

A caught exception reaches the event through `report()`:

```php
try {
    $crm->sync($user);
} catch (CrmException $e) {
    report($e);           // a Sentry issue, and error.* on the event
    // or jot()->error($e); // error.* on the event only, no Sentry issue
}
```

- `rescue(fn () => ...)` reports too.
- With several exceptions, `error.*` describes the last one, usually the one that ended the request. `error.count` shows the others exist, and Sentry holds each of them, see [During a failure](#during-a-failure).
- An event with `error.type` gets the level `error`, even with a 200 response: `report()` means the exception needs attention. Sampling always keeps it.
- Exceptions Laravel does not report, such as validation errors and 404s, add nothing.

## Sampling

The package decides at emit time whether to write the event (tail sampling). It keeps:

- Every event with `http.response.status_code` of 500 or more, a failed job, or an `error.type`.
- Every event slower than `sampling.slow_ms`.
- Every event where a `keepWhen` rule returns `true`, for example enterprise customers.
- A random `sampling.rate` share of all other events.

A `dropWhen` rule removes events that are frequent and alike, such as a health check or a page that polls the server every few seconds. It wins over `keepWhen` and `sampling.rate`. Errors and slow events are still kept.

Register rules in a service provider:

```php
Jot::keepWhen(fn (array $event) => ($event['user.plan'] ?? null) === 'enterprise');
Jot::dropWhen(fn (array $event) => ($event['http.route'] ?? null) === 'health');
```

## Sentry and GlitchTip

With the Sentry SDK for Laravel (`sentry/sentry-laravel`) installed, Jot works with Sentry and needs no setup:

- Every Sentry issue gets the tags `trace_id` and `span_id`, which point to its wide event in the logs.
- With Sentry Logs on, each event also goes to Sentry Logs, with every field as a searchable attribute.

GlitchTip, an open source alternative to Sentry, accepts the same SDK and its logs. Marvelous Digital runs an instance at [glitchtip.marvelous.digital](https://glitchtip.marvelous.digital/).

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

`JOT_SENTRY_LEVEL` sets the lowest level that goes to Sentry, `info` by default. Set it per environment, for example `warning` in production and `info` in staging. `JOT_SENTRY=false` turns off the logs, the tags and the "Jot" context.

> Only sampled events reach Sentry, see [Sampling](#sampling).

> The SDK's own `SENTRY_LOG_LEVEL` (default: `LOG_LEVEL`) filters the same channel for every log line of the app. Keep it at or below `JOT_SENTRY_LEVEL`.

### During a failure

When the app throws, Sentry and Jot each record part of the story:

| | Sentry or GlitchTip | Jot |
|---|---|---|
| Record | one issue per exception, grouped | one wide event per request, job or command |
| Holds | stack trace, breadcrumbs (what the app did before the error), release | user, route, business fields, timings, queries, every job in the trace |
| Written by | the SDK, when Laravel reports the exception | Jot, which adds `error.*` to the current event |

1. The SDK creates the issue. Jot creates none, to avoid a duplicate.
2. Jot adds `error.type` and `exception.message` to the event. The event gets the level `error`, and sampling always keeps it.
3. The issue gets the tags `trace_id` and `span_id`, and a "Jot" context that shows `php artisan jot:query <trace_id>`.

From an issue to its events: run the command shown in the "Jot" context, see [Reading events](#reading-events). With a log backend, set `JOT_SENTRY_LINK` to its search URL, for example `https://grafana.example.com/explore?query={trace_id}`. The "Jot" context then shows a link.

From a user report to the issue: take the `trace_id` from the `X-Request-Id` response header, then search the Sentry issues for `trace_id:<id>`.

## Output shapes

Each entry in `outputs` pairs a log channel with a shape. The shape sets the structure of the event array. The channel's formatter then encodes it as JSON, logfmt or another format.

- `OutputShape::Flat`: one level with dotted keys, `{"http.request.method": "POST"}`. The default. Suits Datadog, Loki and most search backends.
- `OutputShape::Nested`: dotted keys become nested arrays, `{"http": {"request": {"method": "POST"}}}`. XML formatters need this shape.
- `OutputShape::Otel`: the OpenTelemetry (OTel) log data model. Service fields such as `service.version` go to `Resource`, the other fields go to `Attributes`, `trace_id` goes to `TraceId` and `span_id` to `SpanId`. An OpenTelemetry Collector can read a log file in this shape.

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
