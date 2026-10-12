<?php

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
