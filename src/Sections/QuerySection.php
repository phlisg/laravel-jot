<?php

namespace Phlisg\WideEvents\Sections;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Phlisg\WideEvents\Attributes\Key;
use Phlisg\WideEvents\Section;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;

/**
 * The query string, with only the keys listed in jot.fields.query.allow.
 */
final class QuerySection extends Section
{
    #[Key(UrlAttributes::URL_QUERY)]
    public readonly ?string $query;

    public function __construct(Request $request)
    {
        $allow = config('jot.fields.query.allow', []);

        $kept = Arr::where($request->query(), fn ($value, $key) => Str::is($allow, (string) $key));

        $this->query = $kept ? http_build_query($kept) : null;
    }
}
