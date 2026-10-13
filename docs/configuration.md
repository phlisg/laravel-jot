# Configuration

Every setting lives in `config/jot.php`. Jot works without the file: its defaults apply until the app publishes it.

## Publishing

```sh
php artisan jot:install
```

`jot:install` publishes `config/jot.php` and runs `php artisan jot:env`, which adds the Jot keys missing from `.env.example` and `.env`, with their default values. It keeps existing keys, including commented-out ones, so it is safe to run again after an upgrade.

`php artisan vendor:publish --tag=jot-config` publishes the config file alone.

> With a cached config (`php artisan config:cache`), Laravel ignores changes to `.env`. Run `php artisan config:cache` again after each change.

## Environment defaults

The `local` and `testing` environments write every event. Every other environment writes only the events that help debug an incident, listed in [Sampling](../README.md#sampling): `sampling.rate` is `0.0` there and `sampling.client_errors` is `0.01`.

## Settings that need a decision per app

| Setting | Default | Change it when |
|---|---|---|
| `outputs` | a daily JSON file | logs go to a log backend. See [Outputs](outputs.md) |
| `days` | `7` | the server disk is small or large. See [Disk use](../README.md#disk-use) |
| `sampling.rate` | `1.0` local, `0.0` elsewhere | you need every request, for example while a tester follows a session |
| `sampling.client_errors` | `1.0` local, `0.01` elsewhere | 4xx responses matter more or less |
| `sampling.max_per_minute` | `10` | an outage needs more examples per error |
| `sampling.drop` | dev tool routes | other noise fills the log. See [Rules](../README.md#rules) |
| `trace.trust_incoming` | `false` | a proxy sets or strips `traceparent` and `X-Request-Id`. See [Automatic fields](fields.md#automatic-fields) |
| `app.version` | none | you deploy: set `APP_VERSION` to a commit hash or release tag |
| `fields.client.ip` | `anonymized` | you have a legal basis under the GDPR (General Data Protection Regulation) for `full` |
| `fields.query.allow` | empty | you want marketing keys such as `utm_*`. Never tokens |

## The full file

The published file, with every default:

```php
use MarvelousDigital\WideEvents\Enums\OutputShape;

// Local and test runs keep every event; other environments keep what helps debug an incident.
$local = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [

    'enabled' => env('JOT_ENABLED', true),

    // One entry per destination. Each output writes the same event in its own shape.
    'outputs' => [
        // channel null: a daily file in storage/logs with one JSON object per line.
        // level: optional, the lowest event level this output writes.
        ['channel' => env('JOT_CHANNEL'), 'shape' => OutputShape::Flat],
    ],

    // Days of files the default output keeps, one file per day.
    'days' => env('JOT_DAYS', 7),

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
            // Composer hooks and dev tools.
            'package:discover', 'vendor:publish', 'ide-helper:*', 'boost:*', 'filament:upgrade', 'test',
        ],
    ],

    // Logs a warning for each string key written outside a section.
    'strict' => env('JOT_STRICT', false),

    // See "Levels and volume" in the README.
    'sampling' => [
        // Share of normal events to keep, from 0.0 to 1.0. Errors, slow events, failed commands
        // and events raised with jot()->level() are always kept.
        'rate' => env('JOT_SAMPLE_RATE', $local ? 1.0 : 0.0),
        // Share of 4xx responses to keep.
        'client_errors' => env('JOT_SAMPLE_CLIENT_ERRORS', $local ? 1.0 : 0.01),
        'slow_ms' => 1000,
        // Most always-kept events written per minute for each level, error type and route. null: no limit.
        'max_per_minute' => env('JOT_MAX_PER_MINUTE', 10),
        // Livewire updates that only poll or refresh a component.
        'drop_livewire_polls' => true,
        // Events never written unless always kept. Same patterns as jot:query --where: * wildcards, commas for OR.
        'drop' => [
            ['http.route' => 'boost.*,debugbar.*,telescope*,horizon.*'],
        ],
    ],

];
```
