<?php

namespace Phlisg\Jot\Sections;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Phlisg\Jot\Attributes\Key;
use Phlisg\Jot\Section;

/**
 * Always on: the IDs that link one event to the others in its trace.
 */
final class RequestSection extends Section
{
    #[Key('request_id')]
    public readonly ?string $id;

    #[Key('trace_id')]
    public readonly string $traceId;

    #[Key('parent_id')]
    public readonly ?string $parentId;

    #[Key('timestamp')]
    public readonly string $timestamp;

    #[Key('entrypoint')]
    public readonly string $entrypoint;

    #[Key('origin')]
    public readonly string $origin;

    #[Key('duration_ms')]
    public ?int $durationMs = null;

    public function __construct(string $entrypoint, ?string $id, ?string $traceId = null, ?string $parentId = null)
    {
        $this->id = $id;
        $this->traceId = $traceId ?? $id ?? Str::uuid()->toString();
        $this->parentId = $parentId;
        $this->timestamp = now()->toIso8601String();
        $this->entrypoint = $entrypoint;
        $this->origin = Context::get('origin') ?? $entrypoint;
    }

    public static function fromRequest(Request $request): self
    {
        $trusted = config('jot.request_id.trust_incoming', false);

        $id = ($trusted ? $request->header(config('jot.request_id.header', 'X-Request-Id')) : null)
            ?? Str::uuid()->toString();

        return new self('http', $id, ($trusted ? self::incomingTraceId($request) : null) ?? $id);
    }

    /**
     * The trace ID from a W3C traceparent header: version-traceid-parentid-flags.
     */
    private static function incomingTraceId(Request $request): ?string
    {
        return preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/', (string) $request->header('traceparent'), $match)
            ? $match[1]
            : null;
    }
}
