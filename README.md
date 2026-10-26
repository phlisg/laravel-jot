# Laravel Wide Events

Laravel Wide Events writes one structured log event ("wide event") per HTTP request, queued job, Artisan command or scheduled task, with a typed schema that keeps the fields queryable. The package is called Jot, after its helper `jot()`.

![jot:query --follow printing one event per request and queued job as the workbench demos run](docs/images/hero.gif)

## Contents

- [Why](#why)
  - [Terms](#terms)
  - [Jot and Sentry](#jot-and-sentry)
- [What it writes and costs](#what-it-writes-and-costs)
  - [Example event](#example-event)
  - [Where it goes](#where-it-goes)
  - [Disk use](#disk-use)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Production checklist](#production-checklist)
- [Debugging an incident](#debugging-an-incident)
  - [Trace ID on the error page](#trace-id-on-the-error-page)
  - [Trace ID in views](#trace-id-in-views)
- [Troubleshooting](#troubleshooting)
  - [No events for normal requests outside local](#no-events-for-normal-requests-outside-local)
  - [jot:query finds nothing](#jotquery-finds-nothing)
  - [An error is missing](#an-error-is-missing)
- [Adding fields](#adding-fields)
  - [Sections](#sections)
  - [Errors](#errors)
  - [Key collisions](#key-collisions)
- [Reading events](#reading-events)
- [Levels and volume](#levels-and-volume)
  - [Levels](#levels)
  - [Sampling](#sampling)
  - [Rules](#rules)
  - [Recommendations](#recommendations)
  - [Business outcomes](#business-outcomes)
- [Sentry and GlitchTip](#sentry-and-glitchtip)
  - [During a failure](#during-a-failure)
  - [Setup](#setup)
  - [Level settings](#level-settings)
- [Privacy and security](#privacy-and-security)
- [Testing](#testing)
- [Reference](#reference)
- [Versioning](#versioning)
  - [Upgrading to 0.3](#upgrading-to-03)
- [Roadmap](#roadmap)
- [Contributing](#contributing)

## Why

Classic logs scatter many short lines across a request. Finding everything about one failed checkout means searching for strings and stitching lines together by hand.

A wide event (also called a canonical log line) holds all the context for one unit of work in a single log entry: trace ID, HTTP status, duration, user, business data, errors. Code anywhere in the request jots fields down with `jot()`, and Jot writes them once, at the end. The idea comes from [loggingsucks.com](https://loggingsucks.com/), an article on why wide events beat line-by-line logs.

### Terms

- Wide event: one log entry for one request, job, command or scheduled task.
- Trace: a request and every job and command it starts. Their events share one `trace_id`.
- Span: one event inside a trace, with its own `span_id`. `parent_span_id` points to the event that started it.
- Emit: write the event, once the unit of work ends.
- Sampling: the choice, at emit time, of which events to write. "Always kept" events, such as errors, skip the rates, see [Sampling](#sampling).

### Jot and Sentry

Jot works alongside an error tracker. When the app throws, each records part of the story:

| | Sentry or GlitchTip | Jot |
|---|---|---|
| Record | one issue per exception, grouped | one wide event per request, job or command |
| Holds | stack trace, breadcrumbs (what the app did before the error), release | user, route, business fields, timings, queries, every job in the trace |
| Written by | the SDK, when Laravel reports the exception | Jot, which adds `error.*` to the current event |

Sentry is optional. With it installed, each issue gets a `trace_id` tag that leads to the wide event, see [Sentry and GlitchTip](#sentry-and-glitchtip).

## What it writes and costs

### Example event

A checkout request that ended with a declined payment:

```json
{
  "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
  "span_id": "00f067aa0ba902b7",
  "entrypoint": "http",
  "http.request.method": "POST",
  "http.route": "checkout.store",
  "http.response.status_code": 402,
  "duration_ms": 412,
  "payment.provider": "stripe",
  "payment.status": "declined",
  "payment.attempts": 1
}
```

The `payment.*` fields come from the app, see [Adding fields](#adding-fields). Jot adds the rest itself: IDs and duration, HTTP details, user and hashed session, app version and host, errors, database and cache counts, job and command details, Livewire calls. The full list: [Automatic fields](docs/fields.md#automatic-fields).

### Where it goes

- Default: a daily file, `storage/logs/jot-YYYY-MM-DD.log`, one JSON line per event, kept `days` (7) days.
- The app's own logs are untouched: `Log::` calls still go to `laravel.log` or the app's channel.
- Other destinations, such as a log backend or `stderr`: [docs/outputs.md](docs/outputs.md).
- Sentry Logs: see [Sentry and GlitchTip](#sentry-and-glitchtip).

**Staging and production write no successful requests by default.** Outside the `local` and `testing` environments, Jot writes only the events that help debug an incident, listed in [Sampling](#sampling). Set `JOT_SAMPLE_RATE=1.0` to write every request.

### Disk use

An event took about 950 bytes, measured in one app. Jot writes one event per request, plus one per job, command and scheduled task.

| Requests a day | Keep everything, 1 day | Keep everything, 7 days | Defaults, 1 day | Defaults, 7 days |
|---|---|---|---|---|
| 50,000 | 48 MB | 330 MB | 0.5 MB | 3.3 MB |
| 500,000 | 475 MB | 3.3 GB | 4.8 MB | 33 MB |
| 5,000,000 | 4.8 GB | 33 GB | 48 MB | 330 MB |

- Keep everything: `JOT_SAMPLE_RATE=1.0`, as in `local`.
- Defaults: assumes about 1% of requests are errors or slow. An app with more errors writes more.
- 7 days: the default of `days`. Scale to your setting.
- Environments: multiply by the number of environments on the server. Staging, preproduction and production on one server write three sets of files.

To check a server, run `php artisan jot:disk`. It prints the sampling settings and, for each output that writes files, their size, the growth a day, the size at the end of the retention, the free disk space and the days until the disk is full. Run it on each server: it reads only the local files.

## Requirements

- PHP 8.3 or later
- Laravel 11, 12 or 13. Jot relies on the `Context` facade, added in Laravel 11, which shares data across a request and its queued jobs.

## Installation

The package lives in a private GitHub repository and is not on Packagist. To install it:

1. Copy the `auth.json` of another Marvelous project to the app's root. It holds the GitHub token Composer uses to read private repositories:
   ```json
   { "github-oauth": { "github.com": "<token>" } }
   ```
   Laravel's `.gitignore` already keeps `auth.json` out of git.
2. Add the repository to `composer.json`:
   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/Marvelous-Digital/laravel-wide-events" }
   ]
   ```
3. Install:
   ```sh
   composer require marvelous-digital/laravel-wide-events
   ```
4. Check it works: load a page of the app, then run:
   ```sh
   php artisan jot:query
   ```
   The request appears in the list. Outside `local`, normal requests are not written, see [Troubleshooting](#no-events-for-normal-requests-outside-local).

Nothing else is required. The package registers its helper, service provider, global middleware and event listeners itself, and its defaults apply until the app publishes `config/jot.php`.

To change a default, run `php artisan jot:install`. It publishes the config file and adds the Jot keys to `.env`. Every setting: [docs/configuration.md](docs/configuration.md).

### Production checklist

> - Token: CI (continuous integration) and deploy servers need the GitHub token too, in `auth.json` or in the `COMPOSER_AUTH` environment variable, with the same content as `auth.json`.
> - Version: set `APP_VERSION` from the deploy script, for example `APP_VERSION=$(git rev-parse --short HEAD)`. It fills `service.version`.
> - Sentry: set `JOT_SENTRY_LEVEL=warning`, see [Level settings](#level-settings).
> - Proxies: behind a load balancer, configure Laravel's trusted proxies, otherwise every request logs the load balancer's IP address.
> - Config cache: run `php artisan config:cache` after each `.env` change. A cached config ignores `.env`.
> - Files: events are in `storage/logs/jot-YYYY-MM-DD.log` on each server. Run `jot:query` there.

## Debugging an incident

1. Get the trace ID, 32 hex characters, from one of:
   - the Sentry issue: the `trace_id` tag, or the "Jot" context, which shows the command to run
   - the `X-Request-Id` response header, in the browser's network tab
   - the error page, when it shows the ID, see [Trace ID on the error page](#trace-id-on-the-error-page)
2. SSH to the server that wrote the logs and go to the app directory. `jot:query` reads only the files of the server it runs on: a queue worker on another server writes its events there.
3. Print the trace as a tree:
   ```sh
   php artisan jot:query 4bf92f3577b34da6a3ce929d0e0e4736
   ```
   ```
   Trace 4bf92f3577b34da6a3ce929d0e0e4736, 2 event(s)
   http  f56220033574661e  POST checkout.store 200  412ms
   └─ queue  69f6835287391bf7  ChargeCard failed  10ms  RuntimeException: Card network timeout
   ```
4. Print every field of one event, with its span ID from the tree:
   ```sh
   php artisan jot:query 69f6835287391bf7
   ```
5. Without a trace ID, list the recent errors, or the events of one user:
   ```sh
   php artisan jot:query --errors --since=30m
   php artisan jot:query --where=user.id=42 --since=1h
   ```
6. Check what can be missing:
   - Sampling decides event by event, so a trace can be partial. Outside `local`, the successful request that queued a failed job is usually not written.
   - The cap in [Sampling](#sampling) drops errors of one kind past 10 a minute.
   - `jot:query` reads `days` (7) days of files with an ID. Older files are deleted.
7. When Jot also writes to Sentry Logs, `jot:query` prints `Also in Sentry Logs: search trace_id:<id>.` Search Sentry Logs for `trace_id:<id>`.

### Trace ID on the error page

A user or tester sees the error page, never the response header. Jot adds the trace ID to Laravel's default 500 page, so a bug report can quote it: "Reference: 4bf92f35…". It shows only with `APP_DEBUG=false`, since debug mode shows Laravel's debug page instead.

An app with its own `resources/views/errors/500.blade.php` keeps it unchanged. Add the ID there with `@traceId`, see [Trace ID in views](#trace-id-in-views).

`JOT_ERROR_PAGE=false` leaves Laravel's default page as it is.

### Trace ID in views

The `@traceId` Blade directive prints the trace ID of the running event, escaped, or nothing when no event runs. It is the same ID as the `X-Request-Id` header and the `trace_id` field. A person who quotes it gives you the argument of `php artisan jot:query <trace_id>`.

Places to print it:

- The app's own error pages: `500.blade.php`, and the 403, 419 or 429 pages it customizes. Jot's default page covers 500 only.
  ```blade
  <p>Reference: @traceId</p>
  ```
  With `JOT_ENABLED=false`, `@traceId` prints nothing and the label stands alone.
- A support or contact form: a hidden field, so the ticket carries the trace of the page that rendered the form. Submitting the form is a new request with its own trace. The trace also holds the jobs that page dispatched, so a failed export or payment shows up.
  ```blade
  <input type="hidden" name="trace_id" value="@traceId">
  ```
- A footer on staging, so testers quote the ID in bug reports.
  ```blade
  @env('staging')
      <footer>Trace @traceId</footer>
  @endenv
  ```
- A `<meta>` tag, which frontend JavaScript error reporting reads and attaches to its reports.
  ```blade
  <meta name="trace-id" content="@traceId">
  ```
- A mail or notification view rendered in a queued job: it prints the job's trace, which is the trace of the request that dispatched the job.

> Outside `local`, sampling keeps errors and slow events, 1% of 4xx responses and no other events, see [Sampling](#sampling). An ID quoted from a 403, 419 or 429 page, or from a successful page, usually finds nothing. To find every event on staging, set `JOT_SAMPLE_RATE=1.0` and `JOT_SAMPLE_CLIENT_ERRORS=1.0` there.

## Troubleshooting

### No events for normal requests outside local

Outside `local` and `testing`, `sampling.rate` is `0.0`: successful requests are not written, see [Sampling](#sampling). To write them all for a while, for example while a tester follows a session:

1. Set `JOT_SAMPLE_RATE=1.0` in `.env`.
2. Run `php artisan config:cache` if the config is cached, or `php artisan config:clear`.
3. Remove the line and re-cache once done.

### jot:query finds nothing

`jot:query` names the files it read. The usual causes:

- The events went to another output, such as `stderr` or Sentry Logs. `jot:query` prints `Also written to the <channel> channel` for each one.
- The event is older than the files read. Without an ID, `jot:query` reads 1 day: add `--days=7` or `--since=2d`.
- The command ran on another server. Each server reads only its own files.
- Sampling dropped the event, see [No events for normal requests outside local](#no-events-for-normal-requests-outside-local).

### An error is missing

- The cap: past `sampling.max_per_minute` (10) errors of one kind in a minute, the rest are dropped. One kind is one level, `error.type` and route. The first 10 of each minute are there.
- Laravel never reports validation errors and 404s, so they carry no `error.*`. A 4xx response is a `warning`, and only 1% of them are written outside `local`.

## Adding fields

Code anywhere in a request, job, command or scheduled task adds fields with `jot()`:

```php
jot()->add('payment.provider', 'stripe');                    // string key: a typo creates a new field
jot()->add(Field::ExperimentVariant, 'b');                    // backed enum key: fixes the name
PaymentLog::current()->status = PaymentStatus::Declined;      // section: fixes the name and the type
```

Use sections in `app/Jot` for any field you keep. Use string keys while exploring, then move them to a section. `JOT_STRICT=true` logs a warning for each string key, to find them.

Other methods (`addIfMissing`, `increment`, `push`), the `Jot` facade, enum keys, strict mode and field naming conventions: [docs/fields.md](docs/fields.md).

### Sections

A section groups related fields under one key prefix. `php artisan jot:section PaymentLog` creates one in `app/Jot`:

```php
use Phlisg\WideEvents\Section;

final class PaymentLog extends Section
{
    public ?PaymentProvider $provider = null;
    public ?string $intentId = null;
    public ?PaymentStatus $status = null;
    public int $attempts = 0;
}
```

`PaymentLog` writes under `payment`: the class name in snake case, without a `Log` or `Section` suffix. `intentId` becomes `payment.intent_id`, enums are written as their value, `null` properties are skipped. `PaymentLog::current()` returns the same instance for the whole request, so any class can fill it:

```php
$payment = PaymentLog::current();
$payment->provider = PaymentProvider::Stripe;
$payment->attempts++;
```

Flattening rules, `#[Hide]` and `#[Key]` attributes for OpenTelemetry names, constructors and inheritance: [Sections](docs/fields.md#sections).

### Errors

When Laravel reports an exception during a request, job, command or scheduled task, the package adds `error.type` (the exception class), `exception.message` and the other fields listed in [Errors](docs/fields.md#errors). A caught exception reaches the event through `report()`:

```php
try {
    $crm->sync($user);
} catch (CrmException $e) {
    report($e);           // a Sentry issue, and error.* on the event
    // or jot()->error($e); // error.* on the event only, no Sentry issue
}
```

- `rescue(fn () => ...)` reports too.
- With several exceptions, `error.*` describes the last one, usually the one that ended the request. `error.count` shows the others exist, and Sentry holds an issue for each of them.
- An event with `error.type` gets the level `error`, even with a 200 response: `report()` means the exception needs attention.
- Exceptions Laravel does not report, such as validation errors and 404s, add nothing.

### Key collisions

Two writes can target the same key. A collision never throws: logging must not break the app.

| Case | Result | Warning |
|---|---|---|
| The same string key twice | the last value wins, a normal update | no |
| The same section class added twice | the new instance replaces the old one | no |
| A string key and an enum key with the same name | the last value wins | yes |
| A loose field and a section property with the same key | the section wins, whatever the order | yes |
| An app field with the key of an automatic field, such as `user.id` | the automatic field wins | yes |
| A value and child keys under one name (`payment` next to `payment.provider`) | the value is dropped | yes |
| Two section classes with one prefix | they share one slot, the last added replaces the other | no |

The warning goes to the app's default log channel and names the key and both writers. Details: [Key collisions](docs/fields.md#key-collisions).

## Reading events

`php artisan jot:query` reads events back from the log files of the server it runs on:

```sh
php artisan jot:query                        # the 20 most recent events
php artisan jot:query --errors --since=30m   # errors of the last 30 minutes
php artisan jot:query --where=http.route=events.* --where=user.id=42
php artisan jot:query <trace_id>             # one trace, as a tree
php artisan jot:query <span_id>              # one event, with every field
```

At a terminal, the recent events open in a table, newest first, where the cursor starts. Piped or with `-n`, they print as plain lines, oldest first like `tail`, so the newest line sits next to the prompt. `php artisan pail` streams every log message live, Jot events included.

Every option, the interactive table and `--follow`: [docs/querying.md](docs/querying.md).

## Levels and volume

In `local` and `testing`, Jot writes every event except Livewire polls and dev tool routes. In other environments it writes the events that help debug an incident (errors, slow events, failed commands, events raised with `jot()->raise()`) and 1% of 4xx responses, see [Sampling](#sampling). To write more or less, change a rate in `.env` ([Sampling](#sampling)), add a drop or keep rule ([Rules](#rules)), or raise the level of one outcome ([Business outcomes](#business-outcomes)).

### Levels

Jot sets the level of each event from its fields:

| Level | Events |
|---|---|
| `error` | an `error.type` (an uncaught exception, `report()` or `jot()->error()`, see [Errors](#errors)), a status of 500 or more, a failed job |
| `warning` | a 4xx status, a command or scheduled task with a non-zero exit code |
| `notice` | slower than `sampling.slow_ms` (1000 ms) |
| `info` | every other event |
| any | raised with `jot()->raise()`, which never lowers the level the fields give |

The level is a standard Monolog level on the log line, so a log backend can filter on it too.

An optional `level` key on an `outputs` entry writes only events at that level or above. Each output filters on its own, so the main log keeps everything while a second output gets only the problems:

```php
'outputs' => [
    ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
    ['channel' => 'stderr', 'shape' => OutputShape::Flat, 'level' => 'warning'],
],
```

`stderr` is a channel of Laravel's default `config/logging.php`. Sentry Logs has its own setting, see [Level settings](#level-settings).

> The channel's own `level` in `config/logging.php` filters too. Laravel's `slack` channel uses `LOG_LEVEL`, or `critical` when it is unset. Jot gives no event a level above `error` unless the app raises it, so `critical` drops every other event.

### Sampling

Jot writes or drops each event as this table shows. `APP_ENV` picks the column. The cap is explained after the settings.

| Event | `local`, `testing` | Other environments |
|---|---|---|
| Level `error`: an `error.type`, a status of 500 or more, a failed job | written, up to the cap | written, up to the cap |
| Slower than `sampling.slow_ms` (1000 ms) | written, up to the cap | written, up to the cap |
| Command or scheduled task with a non-zero exit code | written, up to the cap | written, up to the cap |
| Raised with `jot()->raise()` | written, up to the cap | written, up to the cap |
| Dev tool route (Laravel Boost, Debugbar, Telescope, Horizon) | dropped | dropped |
| Livewire poll, see [Polling](docs/livewire.md#polling) | dropped | dropped |
| 4xx response | written | 1% at random |
| Any other event | written | none |

The first four rows are the always-kept events. No drop rule removes them, so a failing dev tool route or Livewire poll is still written. A `keepWhen` rule writes a 4xx response or any other event whatever the rate, unless a drop rule matches it first, see [Rules](#rules).

Jot decides when the event is emitted, at the end of the request, job or command, so the status, duration and error are known (tail sampling). Each event is decided alone, so a trace can be partial. Sampling runs before every output, so a dropped event reaches none of them. The first step that matches decides:

| Step | Matches | Result |
|---|---|---|
| 1 | an always-kept event | written if under the cap, else dropped |
| 2 | a Livewire poll, a pattern in `sampling.drop`, a `dropWhen` rule | dropped |
| 3 | a `keepWhen` rule | written |
| 4 | a 4xx response | written at the `sampling.client_errors` rate |
| 5 | any other event | written at the `sampling.rate` rate |

Settings, in the `sampling` key of `config/jot.php`:

| Key | Env variable | `local`, `testing` | Other environments | What it changes |
|---|---|---|---|---|
| `rate` | `JOT_SAMPLE_RATE` | `1.0` | `0.0` | share of other events written, from `0.0` to `1.0` |
| `client_errors` | `JOT_SAMPLE_CLIENT_ERRORS` | `1.0` | `0.01` | share of 4xx responses written |
| `slow_ms` | none | `1000` | `1000` | duration in milliseconds above which an event is slow |
| `max_per_minute` | `JOT_MAX_PER_MINUTE` | `10` | `10` | the cap, `null` turns it off |
| `drop_livewire_polls` | none | `true` | `true` | drops Livewire polls |
| `drop` | none | dev tool routes | dev tool routes | patterns of events to drop, see [Rules](#rules) |

The cap protects the disk during an outage: when the database goes down, every request becomes an error. Jot counts the always-kept events of each kind per minute and drops those above `max_per_minute`. A kind is one combination of level, `error.type` and route (`http.route`, else `job.name`, `console.command` or `schedule.task`). At 10 a minute, one kind writes at most 14,400 events a day, about 14 MB at 950 bytes an event.

- The count lives in the app's default cache store, so a file cache on shared hosting works.
- With a file or database cache, the count has no lock, so the cap is approximate under load.
- When the cache fails, the event is written.

### Rules

A rule picks events by their fields. It takes patterns or a closure.

Patterns use the syntax of `jot:query --where`:

- Each key must match.
- `*` is a wildcard. Commas list alternatives: `'boost.*,horizon.*'`.
- Two dots give a number range, bounds included: `'40..'`, `'..100'`, `'500..599'`. See [Filter](docs/querying.md#filter).
- A missing field never matches. An empty pattern list, `[]`, matches no event.
- A value other than a string is compared as JSON: `true`, `419`, `["a::b"]`.

Find the noise first, then drop it with the same pattern:

1. Run `php artisan jot:query --where=http.route=boost.*` and check that it lists only the noise.
2. Add `['http.route' => 'boost.*']` to `sampling.drop` in `config/jot.php`.

`keepWhen` and `dropWhen` take the same patterns, or a closure for what a pattern cannot express, such as a comparison between two fields. Register them in a service provider. Closures stay out of `config/jot.php`, because `php artisan config:cache` cannot serialize them.

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Phlisg\WideEvents\Facades\Jot;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The uptime monitor's health check.
        Jot::dropWhen(['http.route' => 'health']);

        // Every event of enterprise customers, at any rate.
        Jot::keepWhen(['user.plan' => 'enterprise']);

        // Requests with more than 100 queries, to find N+1 problems.
        Jot::keepWhen(fn (array $event) => ($event['db.query_count'] ?? 0) > 100);
    }
}
```

An N+1 problem is one query per item of a list, where one query for the whole list would do.

Drop rules win over `keepWhen`, and no rule touches an always-kept event, see the steps in [Sampling](#sampling).

### Recommendations

| Situation | Setting |
|---|---|
| Staging, while a tester follows a session | `JOT_SAMPLE_RATE=1.0` for the test, then remove it |
| Production with Sentry Logs | `JOT_SENTRY_LEVEL=warning` |
| An alert channel in `outputs` | `'level' => 'error'` |

Sentry Logs receives each event as a line, and an alert channel notifies on each line, so give both a level. For disk figures, see [Disk use](#disk-use).

### Business outcomes

A declined payment can return 200 and get the level `info`, which sampling drops outside `local` and `testing`. Call `jot()->raise()` to keep it:

```php
use Psr\Log\LogLevel;

jot()->raise(LogLevel::WARNING, 'payment declined');
```

- The level takes a `Psr\Log\LogLevel` constant. An unknown level name is ignored.
- The event gets the fields `level.raised`, in lowercase, and `level.reason`. The reason is optional.
- A call at a higher level replaces `level.reason`, and clears it when it has no reason. A call at the same or a lower level changes nothing.
- A lower level in a later call is ignored. A 500 response stays `error`.
- Output `level` filters see the raised level.

## Sentry and GlitchTip

With the Sentry SDK for Laravel (`sentry/sentry-laravel`) installed, Jot works with Sentry and needs no setup. GlitchTip, an open source alternative to Sentry, accepts the same SDK and its logs. Marvelous Digital runs an instance at [glitchtip.marvelous.digital](https://glitchtip.marvelous.digital/).

Sentry has two places for data:

- Sentry issues: one per exception, grouped, with a stack trace. The SDK creates them. Jot tags each one with `trace_id`, `span_id` and, when the request has a session, `session.hash`.
- Sentry Logs: log lines, searchable by attribute. With `SENTRY_ENABLE_LOGS=true`, Jot sends each kept event there, with every field as an attribute.

### During a failure

1. The SDK creates the issue. Jot creates none, to avoid a duplicate.
2. Jot adds `error.type` and `exception.message` to the event. The event gets the level `error`, and sampling keeps it.
3. The issue gets the tags `trace_id` and `span_id`, and a "Jot" context that shows `php artisan jot:query <trace_id>`.

From an issue to its events: run the command shown in the "Jot" context on the server that wrote the logs, see [Debugging an incident](#debugging-an-incident). With a log backend, set `JOT_SENTRY_LINK` to its search URL, for example `https://grafana.example.com/explore?query={trace_id}`. The "Jot" context then shows a link.

From an issue to the visitor's session: run `php artisan jot:query --where=session.hash=<hash>` with the issue's `session.hash` tag. It lists the events of that session that sampling kept. A login regenerates the session ID, so the requests from before the login carry another hash.

From a user report to the issue: take the trace ID from the `X-Request-Id` response header or the error page, then search the Sentry issues for `trace_id:<id>`.

### Setup

The issue tags need nothing. To send events to Sentry Logs, set in `.env`:

```sh
SENTRY_ENABLE_LOGS=true
JOT_SENTRY_LEVEL=warning
```

`JOT_SENTRY=false` turns off the logs, the tags and the "Jot" context. Only events that sampling keeps reach Sentry, see [Sampling](#sampling).

> With `SENTRY_ENABLE_LOGS=true` and `JOT_SENTRY_LEVEL` unset, every event that sampling keeps goes to Sentry Logs, because the default level is `info`. Set `JOT_SENTRY_LEVEL=warning` in production.

### Level settings

Three settings filter by level. Each one drops events below its level:

| Setting | Where | What it filters | Recommended |
|---|---|---|---|
| `JOT_SENTRY_LEVEL` | `.env`, read by `config/jot.php` | Jot events sent to Sentry Logs. Default `info` | `warning` in production, `info` in staging |
| `SENTRY_LOG_LEVEL` | `.env`, read by `sentry/sentry-laravel` | every log line the app sends to Sentry Logs, Jot events included. Default `LOG_LEVEL` | at or below `JOT_SENTRY_LEVEL` |
| `level` key of an `outputs` entry | `config/jot.php` | Jot events written to that output | `warning` for a second log, `error` for an alert channel |

## Privacy and security

Jot keeps these out of every event:

- Command arguments and options: names only, never their values. Values can carry secrets, such as `--password=secret`.
- Forms: Livewire fields hold method names, property paths and event names, never values or parameters. What a user types stays out.
- Failed logins: `auth.result=failed` only. The email or username that was tried stays out.
- Session ID: never raw, since anyone who reads the logs could take over a live session. `session.hash` is an HMAC (keyed hash) of the ID with the app key, cut to 16 characters.
- Query string: `url.path` holds none, because password reset tokens and signed URL signatures travel there. `url.query` holds only the keys listed in `fields.query.allow`. Never list token keys.
- Values the code needs but the log must not hold: mark the section property `#[Hide]`, see [Sections](docs/fields.md#attributes).

An IP address is personal data under the GDPR (General Data Protection Regulation). The default `anonymized` mode of `fields.client.ip` zeroes the last IPv4 byte (`192.168.1.42` becomes `192.168.1.0`) or the last 80 bits of an IPv6 address. `full` needs a legal basis. Behind a load balancer, configure Laravel's trusted proxies, otherwise every request logs the load balancer's IP.

## Testing

`Jot::fake()` records events instead of writing them. Sampling still applies.

```php
use Phlisg\WideEvents\Facades\Jot;

Jot::fake();

$this->post('/checkout');

Jot::assertEmitted(fn (array $event) => $event['payment.status'] === 'declined');
```

## Reference

- [docs/fields.md](docs/fields.md): every way to add fields, sections, key collisions, field conventions, automatic fields.
- [docs/querying.md](docs/querying.md): every `jot:query` option and the interactive table.
- [docs/configuration.md](docs/configuration.md): the full `config/jot.php`, and the settings to decide per app.
- [docs/outputs.md](docs/outputs.md): outputs, formatters, output shapes, OpenTelemetry.
- [docs/livewire.md](docs/livewire.md): Livewire and Filament fields, polling.
- [docs/examples.md](docs/examples.md): a paid event booking, with a Stripe webhook.
- [docs/architecture.md](docs/architecture.md): how the package collects and writes events.
- [docs/development.md](docs/development.md): Docker setup, tests, workbench, releases.

## Versioning

The package follows semantic versioning and starts at `0.x`. Until `1.0.0`, a minor release (`0.2` to `0.3`) can contain breaking changes. Patch releases (`0.3.0` to `0.3.1`) never do.

- Composer's caret constraint on a `0.x` version stays within one minor release: `composer require marvelous-digital/laravel-wide-events:^0.3` installs `0.3.*` only.
- `CHANGELOG.md` lists the breaking changes of each minor release.

`1.0.0` comes once the public API (`jot()`, the `Jot` facade, `Section`, `config/jot.php`) is stable.

Release steps and release notes: see [Releases](docs/development.md#releases). To install a version in another project: see [Install from another project](docs/development.md#install-from-another-project).

### Upgrading to 0.3

Outside `local` and `testing`, 0.3 writes no normal requests by default: only the always-kept events and 1% of 4xx responses, see [Sampling](#sampling).

- Apps without a published `config/jot.php` get the new defaults at once.
- A published file keeps its old `sampling` and `console` keys, because Laravel merges only the top-level keys of a published config. Its `sampling.rate` stays at `1.0`.

To get the new defaults:

1. Republish the config: `php artisan vendor:publish --tag=jot-config --force`.
2. Reapply the app's changes to `config/jot.php`.
3. Run `php artisan jot:env` to add the new keys to `.env.example` and `.env`.
4. Remove `JOT_SAMPLE_RATE=1.0` from `.env.example` and `.env`. `jot:env` added it before 0.3, and it keeps every request.

A section class with `Log` or `Section` inside its name, such as `LoginLog`, now writes under its full name (`login`). Before 0.3, both words were removed anywhere in the name (`in`).

## Roadmap

- `pennant` group: `feature.<name>` for each Laravel Pennant feature flag checked during a request.
- Export through the OpenTelemetry SDK, detected from `OpenTelemetry\API\Globals::loggerProvider()`, with the active span's trace ID as `trace_id`.

## Contributing

Setup, tests and releases: [docs/development.md](docs/development.md). How the package works inside: [docs/architecture.md](docs/architecture.md).
