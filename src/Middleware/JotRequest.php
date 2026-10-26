<?php

namespace Phlisg\WideEvents\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JotRequest
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('jot.enabled', true)) {
            return $next($request);
        }

        $jot = jot()->initialize($request);

        $response = $next($request);
        $response->headers->set(config('jot.trace.header', 'X-Request-Id'), $jot->traceId());

        return $response;
    }

    /**
     * Runs after the response is sent, so the event holds the final status and duration.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('jot.enabled', true)) {
            return;
        }

        jot()->finalize($request, $response)->emit();
    }
}
