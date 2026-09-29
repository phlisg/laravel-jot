<?php

namespace Phlisg\Jot\Sections;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Phlisg\Jot\Attributes\Key;
use Phlisg\Jot\Section;

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
