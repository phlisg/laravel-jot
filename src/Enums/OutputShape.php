<?php

namespace MarvelousDigital\WideEvents\Enums;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

enum OutputShape: string
{
    case Flat = 'flat';
    case Nested = 'nested';
    case Otel = 'otel';

    public function apply(array $event): array
    {
        return match ($this) {
            self::Flat => $event,
            self::Nested => Arr::undot($event),
            self::Otel => self::otel($event),
        };
    }

    /**
     * The OpenTelemetry log data model: service fields go to Resource, the rest to Attributes.
     */
    private static function otel(array $event): array
    {
        $attributes = Arr::except($event, ['timestamp', 'trace_id', 'span_id']);
        $resource = Arr::where($attributes, fn ($value, $key) => Str::startsWith($key, ['service.', 'deployment.', 'cloud.', 'host.', 'process.', 'laravel.']));

        return [
            'Timestamp' => $event['timestamp'] ?? null,
            'TraceId' => $event['trace_id'] ?? null,
            'SpanId' => $event['span_id'] ?? null,
            'Resource' => $resource,
            'Attributes' => array_diff_key($attributes, $resource),
        ];
    }
}
