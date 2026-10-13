<?php

namespace MarvelousDigital\WideEvents\Tests;

use Illuminate\Support\Facades\Artisan;
use MarvelousDigital\WideEvents\Enums\OutputShape;
use MarvelousDigital\WideEvents\Writer;

function tailed(string $route, array $fields = []): void
{
    app(Writer::class)->write($fields + [
        'timestamp' => now()->toIso8601String(),
        'trace_id' => bin2hex(random_bytes(16)),
        'span_id' => bin2hex(random_bytes(8)),
        'entrypoint' => 'http',
        'http.request.method' => 'GET',
        'http.route' => $route,
        'http.response.status_code' => 200,
        'duration_ms' => 5,
    ]);
}

/**
 * @return list<string> the routes printed, in order
 */
function tail(array $options = []): array
{
    Artisan::call('jot:tail', $options);
    preg_match_all('/GET (\S+)/', Artisan::output(), $matches);

    return $matches[1];
}

beforeEach(function () {
    @unlink(storage_path('logs/jot-tail.log'));
    config([
        'logging.channels.tail' => ['driver' => 'single', 'path' => storage_path('logs/jot-tail.log')],
        'jot.outputs' => [['channel' => 'tail', 'shape' => OutputShape::Flat]],
    ]);
});

it('prints the last events, newest last', function () {
    foreach (['a', 'b', 'c', 'd'] as $i => $route) {
        tailed($route, ['timestamp' => now()->addSeconds($i)->toIso8601String()]);
    }

    expect(tail(['--limit' => 3]))->toBe(['b', 'c', 'd']);

    $this->artisan('jot:tail')
        ->expectsOutputToContain('http  ')
        ->expectsOutputToContain('Run jot:query <trace_id> for a trace')
        ->assertSuccessful();
});

it('filters on fields, with wildcards', function () {
    tailed('bookings.store', ['user.id' => 7]);
    tailed('bookings.show', ['user.id' => 8]);
    tailed('home');

    expect(tail(['--where' => ['user.id=7']]))->toBe(['bookings.store'])
        ->and(tail(['--where' => ['http.route=bookings.*']]))->toBe(['bookings.store', 'bookings.show'])
        ->and(tail(['--where' => ['http.route=bookings.*', 'user.id=8']]))->toBe(['bookings.show']);
});

it('keeps only errors, or only slow events', function () {
    tailed('ok');
    tailed('server', ['http.response.status_code' => 503]);
    tailed('thrown', ['error.type' => 'RuntimeException']);
    tailed('slow', ['duration_ms' => 1500]);

    expect(tail(['--errors' => true]))->toBe(['server', 'thrown'])
        ->and(tail(['--slow' => true]))->toBe(['slow']);
});

it('keeps only events since a duration', function () {
    tailed('old', ['timestamp' => now()->subHours(2)->toIso8601String()]);
    tailed('new');

    expect(tail(['--since' => '1h']))->toBe(['new']);

    $this->artisan('jot:tail', ['--since' => 'an hour'])->assertExitCode(2);
});

it('prints JSON lines', function () {
    tailed('home', ['span_id' => 'aaaabbbbccccdddd']);

    Artisan::call('jot:tail', ['--json' => true]);

    expect(json_decode(trim(Artisan::output()), true))->toMatchArray(['span_id' => 'aaaabbbbccccdddd', 'http.route' => 'home']);
});

it('warns when nothing matches', function () {
    tailed('home');

    expect(Artisan::call('jot:tail', ['--where' => ['http.route=nope']]))->toBe(1)
        ->and(Artisan::output())->toContain('No matching event')->toContain('jot-tail.log');
});
