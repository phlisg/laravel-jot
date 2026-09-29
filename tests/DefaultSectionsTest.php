<?php

use Illuminate\Http\Request;

function initialized(string $uri = '/checkout', array $server = []): array
{
    return jot()->initialize(Request::create($uri, 'POST', server: $server))->event();
}

describe('request IDs', function () {
    it('ignores an incoming request ID unless trusted', function () {
        expect(initialized(server: ['HTTP_X_REQUEST_ID' => 'req_123'])['request_id'])->not->toBe('req_123');
    });

    it('keeps a trusted incoming request ID, and reuses it as the trace ID', function () {
        config(['jot.request_id.trust_incoming' => true]);

        expect(initialized(server: ['HTTP_X_REQUEST_ID' => 'req_123']))
            ->toMatchArray(['request_id' => 'req_123', 'trace_id' => 'req_123']);
    });

    it('reads a trusted trace ID from a W3C traceparent header', function () {
        config(['jot.request_id.trust_incoming' => true]);

        $event = initialized(server: ['HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

        expect($event['trace_id'])->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
    });
});

describe('http', function () {
    it('writes the path with its leading slash and without the query string', function () {
        expect(initialized('/events/42?token=secret'))
            ->toMatchArray(['http.request.method' => 'POST', 'url.path' => '/events/42']);
    });

    it('writes nothing when switched off', function () {
        config(['jot.fields.http' => false]);

        expect(initialized())->not->toHaveKey('url.path');
    });
});

describe('query', function () {
    it('keeps only the allowed keys', function () {
        config(['jot.fields.query.allow' => ['utm_*']]);

        expect(initialized('/events?utm_source=newsletter&token=secret')['url.query'])->toBe('utm_source=newsletter');
    });

    it('writes nothing with no key allowed', function () {
        expect(initialized('/events?utm_source=newsletter'))->not->toHaveKey('url.query');
    });
});

describe('client', function () {
    it('anonymizes the IP address by default', function (string $ip, string $expected) {
        expect(initialized(server: ['REMOTE_ADDR' => $ip])['client.address'])->toBe($expected);
    })->with([
        'IPv4' => ['192.168.1.42', '192.168.1.0'],
        'IPv6' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3::'],
    ]);

    it('writes the full IP address when configured', function () {
        config(['jot.fields.client.ip' => 'full']);

        expect(initialized(server: ['REMOTE_ADDR' => '192.168.1.42'])['client.address'])->toBe('192.168.1.42');
    });

    it('cuts the user agent to 256 characters', function () {
        expect(initialized(server: ['HTTP_USER_AGENT' => str_repeat('a', 300)])['user_agent.original'])
            ->toHaveLength(256);
    });
});

it('writes the app fields', function () {
    config(['jot.app.version' => 'abc123']);

    expect(initialized())->toMatchArray([
        'service.version' => 'abc123',
        'process.runtime.version' => PHP_VERSION,
        'laravel.version' => app()->version(),
    ]);
});

it('puts the identity fields first, before the app data', function () {
    jot()->add('payment.provider', 'stripe');

    expect(array_slice(array_keys(initialized()), 0, 4))->toBe(['request_id', 'trace_id', 'timestamp', 'entrypoint']);
});

it('keeps a reserved value over an app field with the same key', function () {
    jot()->add('url.path', '/spoofed');

    expect(initialized('/checkout')['url.path'])->toBe('/checkout');
});
