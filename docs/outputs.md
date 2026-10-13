# Outputs

Each entry in `outputs` in `config/jot.php` pairs a log channel with a shape. Jot writes every kept event once per entry.

```php
'outputs' => [
    ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
    ['channel' => 'stderr', 'shape' => OutputShape::Flat, 'level' => 'warning'],
],
```

- `channel`: a channel of `config/logging.php`. `null`, the default, writes the daily file `storage/logs/jot-YYYY-MM-DD.log`.
- `shape`: the structure of the event array, see [Output shapes](#output-shapes).
- `level`: optional, the lowest event level this output writes, see [Levels](../README.md#levels).

With `sentry/sentry-laravel` installed and `SENTRY_ENABLE_LOGS=true`, Jot adds the SDK's `sentry_logs` channel on its own, see [Sentry and GlitchTip](../README.md#sentry-and-glitchtip).

`jot:query` reads only the outputs that write files: the default file and any `single` or `daily` channel.

## Formatter

The default output already writes JSON, one object per line. A channel listed in `outputs` uses its own formatter from `config/logging.php`. Add this line to the channel there, so the log backend can index each field:

```php
'formatter' => Monolog\Formatter\JsonFormatter::class,
```

## Output shapes

The shape sets the structure of the event array. The channel's formatter then encodes it as JSON, logfmt or another format.

- `OutputShape::Flat`: one level with dotted keys, `{"http.request.method": "POST"}`. The default. Suits Datadog, Loki and most search backends.
- `OutputShape::Nested`: dotted keys become nested arrays, `{"http": {"request": {"method": "POST"}}}`. XML formatters need this shape.
- `OutputShape::Otel`: the OpenTelemetry (OTel) log data model. Service fields such as `service.version` go to `Resource`, the other fields go to `Attributes`, `trace_id` goes to `TraceId` and `span_id` to `SpanId`. An OpenTelemetry Collector can read a log file in this shape.

> OTel marks some names as incubating, which means a later OTel release can still rename them. Jot uses these incubating names: `host.name`, `user.id`, `http.request.body.size`, `http.response.body.size` and `process.runtime.version`.
