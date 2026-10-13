<?php

use MarvelousDigital\WideEvents\Enums\OutputShape;

// Local and test runs keep every event the drop rules leave; other environments keep what helps debug an incident.
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

    // Adds the trace ID to Laravel's default 500 page. An app's own errors/500.blade.php is left alone.
    'error_page' => env('JOT_ERROR_PAGE', true),

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
        // and events raised with jot()->raise() are always kept.
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
