<?php

namespace MarvelousDigital\WideEvents\Sections;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Section;

/**
 * Always on: the W3C Trace Context IDs that link one event to the others in its trace.
 */
final class RequestSection extends Section
{
    #[Key('trace_id')]
    public readonly string $traceId;

    #[Key('span_id')]
    public readonly string $spanId;

    #[Key('parent_span_id')]
    public readonly ?string $parentSpanId;

    #[Key('timestamp')]
    public readonly string $timestamp;

    #[Key('entrypoint')]
    public readonly string $entrypoint;

    #[Key('origin')]
    public readonly string $origin;

    #[Key('duration_ms')]
    public ?int $durationMs = null;

    public function __construct(string $entrypoint, ?string $traceId = null, ?string $parentSpanId = null)
    {
        $this->traceId = $traceId ?? bin2hex(random_bytes(16));
        $this->spanId = bin2hex(random_bytes(8));
        $this->parentSpanId = $parentSpanId;
        $this->timestamp = now()->toIso8601String();
        $this->entrypoint = $entrypoint;
        $this->origin = Context::get('origin') ?? $entrypoint;
    }

    /**
     * Continues the trace of a trusted W3C traceparent header: version-traceid-parentid-flags.
     */
    public static function fromRequest(Request $request): self
    {
        $trusted = config('jot.trace.trust_incoming', false);

        return $trusted && preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', (string) $request->header('traceparent'), $match)
            ? new self('http', $match[1], $match[2])
            : new self('http');
    }

    /**
     * Continues the trace in Context, as a child of the span that set it.
     */
    public static function fromContext(string $entrypoint): self
    {
        return new self($entrypoint, Context::get('trace_id'), Context::get('span_id'));
    }
}
