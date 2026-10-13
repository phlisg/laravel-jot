# Outputs

An output is one place where Jot writes its events: a Laravel log channel, plus the shape of the event array. Out of the box Jot has one output, a daily JSON file in `storage/logs` that `php artisan jot:query` reads. Add an output to send events to a log backend, `stderr` or an OpenTelemetry Collector.

## Contents

- [Which shape to use](#which-shape-to-use)
- [Adding an output](#adding-an-output)
  - [Default file](#default-file)
  - [A JSON file for a log shipper](#a-json-file-for-a-log-shipper)
  - [Warnings and errors on stderr](#warnings-and-errors-on-stderr)
  - [OpenTelemetry Collector](#opentelemetry-collector)
  - [Sentry Logs](#sentry-logs)
- [Formatter](#formatter)
- [Output shapes](#output-shapes)
  - [Flat](#flat)
  - [Nested](#nested)
  - [Otel](#otel)
- [Outputs that jot:query reads](#outputs-that-jotquery-reads)

## Which shape to use

| Destination | Shape |
|---|---|
| The default file, `jot:query`, Datadog, Loki and most search backends | `OutputShape::Flat` (the default) |
| A formatter or tool that needs nested objects, such as an XML formatter | `OutputShape::Nested` |
| An OpenTelemetry (OTel) Collector reading a log file | `OutputShape::Otel` |

`jot:query` reads all three shapes. Each shape is shown on one event in [Output shapes](#output-shapes) below.

## Adding an output

Outputs live in the `outputs` array of `config/jot.php`. Publish the file first with `php artisan jot:install`, see [Configuration](configuration.md). Each entry is one output, and Jot writes every kept event once per entry.

| Key | Required | What it does |
|---|---|---|
| `channel` | no | A channel name from `config/logging.php`. `null` or missing: the default daily file. |
| `shape` | no | `OutputShape::Flat`, `Nested` or `Otel`, or the strings `'flat'`, `'nested'`, `'otel'`. Default `Flat`. |
| `level` | no | The lowest event level this output writes, a Monolog level name such as `warning`. Missing: every level. See [Levels](../README.md#levels). |

To swap the default file for one channel without editing the config, set `JOT_CHANNEL` in `.env`, for example `JOT_CHANNEL=stderr`. The published file reads it in its first entry.

### Default file

```php
'outputs' => [
    ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
],
```

With `JOT_CHANNEL` empty, Jot writes `storage/logs/jot-YYYY-MM-DD.log`, one JSON object per line, and keeps `days` files (7 by default). The event sits under `context` on each line:

```json
{"message":"jot","context":{"trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","span_id":"00f067aa0ba902b7","timestamp":"2026-10-13T14:02:11+00:00","entrypoint":"http","duration_ms":412,"http.request.method":"POST","http.route":"checkout.store","http.response.status_code":402,"user.id":"1842","service.version":"a1b2c3d","host.name":"web-1","payment.provider":"stripe","payment.status":"declined"},"level":300,"level_name":"WARNING","channel":"jot","datetime":"2026-10-13T14:02:11+00:00"}
```

### A JSON file for a log shipper

Add a channel to `config/logging.php`. The `formatter` line makes each line JSON, see [Formatter](#formatter):

```php
'jot-shipper' => [
    'driver' => 'daily',
    'path' => storage_path('logs/jot-shipper.log'),
    'days' => 7,
    'formatter' => Monolog\Formatter\JsonFormatter::class,
],
```

Then point an output at it in `config/jot.php`. Keep the first entry to keep the default file too:

```php
'outputs' => [
    ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
    ['channel' => 'jot-shipper', 'shape' => OutputShape::Flat],
],
```

`jot:query` reads both files and prints each event once.

### Warnings and errors on stderr

Laravel's default `config/logging.php` already has a `stderr` channel. This output sends it only the events at `warning` or above:

```php
'outputs' => [
    ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
    ['channel' => 'stderr', 'shape' => OutputShape::Flat, 'level' => 'warning'],
],
```

For JSON on `stderr`, add `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` to `.env`. The `stderr` channel reads it.

> The channel's own `level` in `config/logging.php` filters too. `stderr` uses `LOG_LEVEL`. An output gets an event only when both levels let it through.

### OpenTelemetry Collector

Use the same channel as for a log shipper, with the OTel shape:

```php
'outputs' => [
    ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
    ['channel' => 'jot-shipper', 'shape' => OutputShape::Otel],
],
```

### Sentry Logs

With `sentry/sentry-laravel` installed and `SENTRY_ENABLE_LOGS=true`, Jot adds an output to the SDK's `sentry_logs` channel on its own, in the flat shape. See [Sentry and GlitchTip](../README.md#sentry-and-glitchtip).

## Formatter

The default file is JSON already. Any other channel uses the formatter set in `config/logging.php`, and Laravel's default is a text line. Add this line to the channel there, so the log backend can index each field:

```php
'formatter' => Monolog\Formatter\JsonFormatter::class,
```

A Laravel channel also writes the app's Context under `extra`, so the trace IDs appear twice on the line. The default file leaves them out.

## Output shapes

The shape sets the structure of the event array. The channel's formatter then encodes it, as JSON or another format. The examples below show the same event in each shape, as the `context` of the log line.

### Flat

One level, with dotted keys. This is the shape Jot builds, unchanged.

```json
{
    "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
    "span_id": "00f067aa0ba902b7",
    "timestamp": "2026-10-13T14:02:11+00:00",
    "entrypoint": "http",
    "duration_ms": 412,
    "http.request.method": "POST",
    "http.route": "checkout.store",
    "http.response.status_code": 402,
    "user.id": "1842",
    "service.version": "a1b2c3d",
    "host.name": "web-1",
    "payment.provider": "stripe",
    "payment.status": "declined"
}
```

### Nested

Each dot in a key becomes one level of nesting.

```json
{
    "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
    "span_id": "00f067aa0ba902b7",
    "timestamp": "2026-10-13T14:02:11+00:00",
    "entrypoint": "http",
    "duration_ms": 412,
    "http": {
        "request": {
            "method": "POST"
        },
        "route": "checkout.store",
        "response": {
            "status_code": 402
        }
    },
    "user": {
        "id": "1842"
    },
    "service": {
        "version": "a1b2c3d"
    },
    "host": {
        "name": "web-1"
    },
    "payment": {
        "provider": "stripe",
        "status": "declined"
    }
}
```

### Otel

The OpenTelemetry log data model:

- `Timestamp`, `TraceId`, `SpanId`: from `timestamp`, `trace_id` and `span_id`.
- `Resource`: the fields that describe the server and the app, the keys that start with `service.`, `deployment.`, `cloud.`, `host.`, `process.` or `laravel.`.
- `Attributes`: every other field, keys kept flat.

```json
{
    "Timestamp": "2026-10-13T14:02:11+00:00",
    "TraceId": "4bf92f3577b34da6a3ce929d0e0e4736",
    "SpanId": "00f067aa0ba902b7",
    "Resource": {
        "service.version": "a1b2c3d",
        "host.name": "web-1"
    },
    "Attributes": {
        "entrypoint": "http",
        "duration_ms": 412,
        "http.request.method": "POST",
        "http.route": "checkout.store",
        "http.response.status_code": 402,
        "user.id": "1842",
        "payment.provider": "stripe",
        "payment.status": "declined"
    }
}
```

> OTel marks some field names as incubating: a later OTel release can still rename them. Jot uses these incubating names: `host.name`, `user.id`, `http.request.body.size`, `http.response.body.size` and `process.runtime.version`.

## Outputs that jot:query reads

`jot:query` reads the outputs that write files: the default file, and any channel with the `single` or `daily` driver. It skips the others, such as `stderr` or `sentry_logs`. Search those in their own backend.
