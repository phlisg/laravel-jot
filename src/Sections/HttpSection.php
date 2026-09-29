<?php

namespace Phlisg\Jot\Sections;

use Illuminate\Http\Request;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Phlisg\Jot\Attributes\Key;
use Phlisg\Jot\Section;

/**
 * The request half of the http group. The route, status code, response size
 * and duration are only final at the end of the request.
 */
final class HttpSection extends Section
{
    #[Key(HttpAttributes::HTTP_REQUEST_METHOD)]
    public readonly string $method;

    // Path only: tokens and signatures travel in the query string, see QuerySection.
    #[Key(UrlAttributes::URL_PATH)]
    public readonly string $path;

    #[Key('http.request.header.content-type')]
    public readonly ?string $contentType;

    #[Key(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE)]
    public readonly ?int $bodySize;

    public function __construct(Request $request)
    {
        $this->method = $request->method();
        $this->path = $request->getPathInfo();
        $this->contentType = $request->header('Content-Type');
        $this->bodySize = $request->header('Content-Length') !== null ? (int) $request->header('Content-Length') : null;
    }
}
