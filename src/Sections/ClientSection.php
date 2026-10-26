<?php

namespace Phlisg\WideEvents\Sections;

use Illuminate\Http\Request;
use Phlisg\WideEvents\Attributes\Key;
use Phlisg\WideEvents\Section;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use Symfony\Component\HttpFoundation\IpUtils;

final class ClientSection extends Section
{
    #[Key(UserAgentAttributes::USER_AGENT_ORIGINAL)]
    public readonly ?string $userAgent;

    #[Key(ClientAttributes::CLIENT_ADDRESS)]
    public readonly ?string $address;

    public function __construct(Request $request)
    {
        $userAgent = $request->userAgent();

        $this->userAgent = config('jot.fields.client.user_agent', true) && $userAgent !== null
            ? mb_substr($userAgent, 0, 256)
            : null;

        $this->address = self::address($request->ip(), config('jot.fields.client.ip', 'anonymized'));
    }

    /**
     * An IP address is personal data under the GDPR: anonymized zeroes the last
     * IPv4 byte, or the last 80 bits (10 bytes) of an IPv6 address.
     */
    private static function address(?string $ip, string|false $mode): ?string
    {
        return match (true) {
            $ip === null, $mode === false => null,
            $mode === 'full' => $ip,
            default => IpUtils::anonymize($ip, 1, 10),
        };
    }
}
