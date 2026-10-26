<?php

namespace Phlisg\WideEvents\Sections;

use Illuminate\Http\Request;
use OpenTelemetry\SemConv\Incubating\Attributes\UserIncubatingAttributes;
use Phlisg\WideEvents\Attributes\Key;
use Phlisg\WideEvents\Section;

/**
 * Read at the end of the request, so a login during the request is included.
 */
final class UserSection extends Section
{
    #[Key(UserIncubatingAttributes::USER_ID)]
    public int|string|null $id = null;

    #[Key('session.hash')]
    public ?string $sessionHash = null;

    #[Key('auth.result')]
    public ?string $authResult = null;

    #[Key('app.locale')]
    public ?string $locale = null;

    public function finish(Request $request): void
    {
        if (config('jot.fields.user', true)) {
            $this->id = $request->user()?->getAuthIdentifier();
        }

        if (config('jot.fields.session', true) && $request->hasSession()) {
            $this->sessionHash = self::hash($request->session()->getId());
        }

        if (config('jot.fields.locale', true)) {
            $this->locale = app()->getLocale();
        }
    }

    /**
     * Never the raw ID: whoever reads the logs could take over the session.
     */
    public static function hash(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 16);
    }
}
