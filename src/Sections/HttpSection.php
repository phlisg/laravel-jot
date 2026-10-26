<?php

namespace Phlisg\WideEvents\Sections;

use Illuminate\Http\Request;
use Phlisg\WideEvents\Attributes\Key;
use Phlisg\WideEvents\Section;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route, status code and response size are only final at the end of the request, see finish().
 */
final class HttpSection extends Section
{
    #[Key(HttpAttributes::HTTP_REQUEST_METHOD)]
    public readonly string $method;

    // Path only: tokens and signatures travel in the query string, see QuerySection.
    #[Key(UrlAttributes::URL_PATH)]
    public readonly string $path;

    #[Key(HttpAttributes::HTTP_ROUTE)]
    public ?string $route = null;

    #[Key('http.request.header.content-type')]
    public readonly ?string $contentType;

    // A proxy's own request ID, read only when trusted, to find the request in the proxy's logs.
    #[Key('http.request.header.x-request-id')]
    public readonly ?string $requestId;

    #[Key(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE)]
    public readonly ?int $bodySize;

    #[Key(HttpAttributes::HTTP_RESPONSE_STATUS_CODE)]
    public ?int $statusCode = null;

    #[Key(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE)]
    public ?int $responseSize = null;

    public function __construct(Request $request)
    {
        $this->method = $request->method();
        $this->path = $request->getPathInfo();
        $this->contentType = $request->header('Content-Type');
        $this->requestId = config('jot.trace.trust_incoming', false) ? $request->header(config('jot.trace.header', 'X-Request-Id')) : null;
        $this->bodySize = $request->header('Content-Length') !== null ? (int) $request->header('Content-Length') : null;
    }

    public function finish(Request $request, Response $response): void
    {
        $this->route = $request->route()?->getName();
        $this->statusCode = $response->getStatusCode();

        $content = $response->getContent();
        $this->responseSize = $content === false ? null : strlen($content);
    }
}
