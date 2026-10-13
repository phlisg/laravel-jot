<?php

use MarvelousDigital\WideEvents\Facades\Jot;
use MarvelousDigital\WideEvents\Writer;
use Psr\Log\LogLevel;

function keeps(array $event): bool
{
    return app(Writer::class)->keep($event + ['span_id' => 'a']);
}

beforeEach(fn () => config(['jot.sampling.rate' => 0.0, 'jot.sampling.client_errors' => 0.0]));

it('samples 4xx responses at their own rate', function () {
    expect(keeps(['http.response.status_code' => 419]))->toBeFalse();

    config(['jot.sampling.client_errors' => 1.0]);

    expect(keeps(['http.response.status_code' => 419]))->toBeTrue()
        ->and(keeps(['http.response.status_code' => 200]))->toBeFalse();
});

it('always keeps failed commands and scheduled tasks', function () {
    expect(keeps(['console.exit_code' => 1]))->toBeTrue()
        ->and(keeps(['schedule.exit_code' => 2]))->toBeTrue();
});

it('always keeps events raised with raise(), even when a rule drops them', function () {
    Jot::dropWhen(fn () => true);

    expect(keeps(['level.raised' => LogLevel::NOTICE]))->toBeTrue();
});

it('drops events matching a pattern from the config', function () {
    config(['jot.sampling.rate' => 1.0, 'jot.sampling.drop' => [['http.route' => 'boost.*,horizon.*', 'http.request.method' => 'GET']]]);

    expect(keeps(['http.route' => 'boost.browser-logs', 'http.request.method' => 'GET']))->toBeFalse()
        ->and(keeps(['http.route' => 'horizon.stats', 'http.request.method' => 'GET']))->toBeFalse()
        ->and(keeps(['http.route' => 'horizon.stats', 'http.request.method' => 'POST']))->toBeTrue()
        ->and(keeps(['http.route' => 'pages.index', 'http.request.method' => 'GET']))->toBeTrue()
        ->and(keeps(['http.route' => 'boost.browser-logs', 'http.response.status_code' => 500]))->toBeTrue();
});

it('takes patterns in keepWhen and dropWhen', function () {
    Jot::keepWhen(['user.plan' => 'enterprise']);

    expect(keeps(['user.plan' => 'enterprise']))->toBeTrue()
        ->and(keeps(['user.plan' => 'free']))->toBeFalse();

    config(['jot.sampling.rate' => 1.0]);
    Jot::dropWhen(['http.response.status_code' => '204']);

    expect(keeps(['http.response.status_code' => 204]))->toBeFalse();
});

it('drops Livewire updates that only poll or refresh', function () {
    config(['jot.sampling.rate' => 1.0]);
    $update = ['http.route' => 'default.livewire.update', 'livewire.components' => ['checkout']];

    expect(keeps($update + ['livewire.poll' => true]))->toBeFalse()
        ->and(keeps($update))->toBeFalse()
        ->and(keeps($update + ['livewire.calls' => ['checkout::pay']]))->toBeTrue()
        ->and(keeps($update + ['livewire.updates' => ['email']]))->toBeTrue()
        ->and(keeps(['http.route' => 'default.livewire.update']))->toBeTrue();

    config(['jot.sampling.drop_livewire_polls' => false]);

    expect(keeps($update + ['livewire.poll' => true]))->toBeTrue();
});

it('limits the always-kept events of one kind per minute', function () {
    $this->freezeTime();
    config(['jot.sampling.max_per_minute' => 2]);
    $error = ['error.type' => 'PDOException', 'http.route' => 'checkout'];

    expect(array_map(fn () => keeps($error), range(1, 3)))->toBe([true, true, false])
        ->and(keeps(['error.type' => 'PDOException', 'http.route' => 'home']))->toBeTrue()
        ->and(keeps(['error.type' => 'TypeError', 'http.route' => 'checkout']))->toBeTrue();

    config(['jot.sampling.max_per_minute' => null]);

    expect(keeps($error))->toBeTrue();
});

it('raises the level, never lowers it', function () {
    jot()->raise(LogLevel::WARNING, 'payment declined')->raise(LogLevel::NOTICE, 'ignored');

    expect(jot()->event())->toMatchArray(['level.raised' => 'warning', 'level.reason' => 'payment declined'])
        ->and(Writer::level(jot()->event()))->toBe(LogLevel::WARNING)
        ->and(Writer::level(['level.raised' => 'notice', 'http.response.status_code' => 500]))->toBe(LogLevel::ERROR);
});

it('ignores an unknown level, and writes the reason of the last raise', function () {
    jot()->raise('nonsense', 'ignored')->raise('NOTICE', 'slow checkout')->raise(LogLevel::ERROR);

    expect(jot()->event())->toMatchArray(['level.raised' => 'error'])->not->toHaveKey('level.reason');
});

it('lets drop rules win over keep rules, and ignores empty patterns', function () {
    config(['jot.sampling.drop' => [[]]]);
    Jot::keepWhen(['user.plan' => 'enterprise']);
    Jot::dropWhen([]);
    Jot::dropWhen(['http.route' => 'health']);

    expect(keeps(['user.plan' => 'enterprise']))->toBeTrue()
        ->and(keeps(['user.plan' => 'enterprise', 'http.route' => 'health']))->toBeFalse();
});
