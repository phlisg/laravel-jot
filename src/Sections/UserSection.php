<?php

namespace MarvelousDigital\WideEvents\Sections;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use MarvelousDigital\WideEvents\Attributes\Key;
use MarvelousDigital\WideEvents\Section;
use OpenTelemetry\SemConv\Incubating\Attributes\UserIncubatingAttributes;

/**
 * Records only a user the app loaded itself, from its auth events or the default guard.
 */
final class UserSection extends Section
{
    #[Key(UserIncubatingAttributes::USER_ID)]
    public int|string|null $id = null;

    #[Key('user.guard')]
    public ?string $guard = null;

    #[Key('session.hash')]
    public ?string $sessionHash = null;

    #[Key('auth.result')]
    public ?string $authResult = null;

    #[Key('app.locale')]
    public ?string $locale = null;

    private ?Authenticatable $user = null;

    public function authenticated(string $guard, Authenticatable $user): void
    {
        [$this->guard, $this->user, $this->id] = [$guard, $user, $user->getAuthIdentifier()];
    }

    public function forget(string $guard): void
    {
        if ($this->guard === $guard) {
            [$this->guard, $this->user, $this->id] = [null, null, null];
        }
    }

    /** The user chosen in finish(). */
    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function finish(Request $request): void
    {
        if (! config('jot.fields.user', true)) {
            [$this->guard, $this->user, $this->id] = [null, null, null];
        } elseif (auth()->guard()->hasUser()) {
            // hasUser() first: user() alone would load the user, and could sign in from the remember-me cookie after the session is saved.
            $this->authenticated(auth()->getDefaultDriver(), auth()->guard()->user());
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
