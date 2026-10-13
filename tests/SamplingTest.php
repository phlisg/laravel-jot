<?php

use Illuminate\Support\Facades\Cache;
use MarvelousDigital\WideEvents\Facades\Jot;
use MarvelousDigital\WideEvents\Writer;
use Psr\Log\LogLevel;

function keeps(array $event): bool
{
    return app(Writer::class)->keep($event + ['span_id' => 'a']);
}

/**
 * config/jot.php as an app reads it in that environment. null: APP_ENV unset.
 */
function configIn(?string $env): array
{
    [$server, $environment, $putenv] = [$_SERVER, $_ENV, getenv('APP_ENV')];
    unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
    putenv($env === null ? 'APP_ENV' : "APP_ENV=$env");

    if ($env !== null) {
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $env;
    }

    try {
        return require __DIR__.'/../config/jot.php';
    } finally {
        [$_SERVER, $_ENV] = [$server, $environment];
        putenv($putenv === false ? 'APP_ENV' : "APP_ENV=$putenv");
    }
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

    expect(jot()->event())->toMatchArray(['level.raised' => 'error'])->not->toHaveKeys(['level.reason', 'error.type']);
});

it('lets drop rules win over keep rules, and ignores empty patterns', function () {
    config(['jot.sampling.drop' => [[]]]);
    Jot::keepWhen(['user.plan' => 'enterprise']);
    Jot::keepWhen([]);
    Jot::dropWhen([]);
    Jot::dropWhen(['http.route' => 'health']);

    expect(keeps(['user.plan' => 'enterprise']))->toBeTrue()
        ->and(keeps(['user.plan' => 'free']))->toBeFalse()
        ->and(keeps(['user.plan' => 'enterprise', 'http.route' => 'health']))->toBeFalse();
});

describe('defaults', function () {
    it('keeps every event in local and testing, and few in other environments', function (?string $env, float $rate, float $clientErrors) {
        $sampling = configIn($env)['sampling'];

        expect($sampling['rate'])->toBe($rate)
            ->and($sampling['client_errors'])->toBe($clientErrors);
    })->with([
        'local' => ['local', 1.0, 1.0],
        'testing' => ['testing', 1.0, 1.0],
        'staging' => ['staging', 0.0, 0.01],
        'production' => ['production', 0.0, 0.01],
        'APP_ENV unset' => [null, 0.0, 0.01],
    ]);

    it('caps always-kept events at 10 per minute, and keeps 7 days of files', function () {
        expect(configIn('production'))->toMatchArray(['days' => 7])
            ->sampling->toMatchArray(['max_per_minute' => 10, 'slow_ms' => 1000, 'drop_livewire_polls' => true]);
    });

    it('drops dev tool routes', function (string $route) {
        config(['jot.sampling.rate' => 1.0]);

        expect(keeps(['http.route' => $route]))->toBeFalse()
            ->and(keeps(['http.route' => 'pages.index']))->toBeTrue();
    })->with(['boost.browser-logs', 'debugbar.assets.js', 'telescope', 'telescope-api.requests', 'horizon.stats.index']);

    it('drops Livewire polls with a config published before that setting existed', function () {
        config(['jot.sampling' => ['rate' => 1.0, 'slow_ms' => 1000]]);

        expect(keeps(['livewire.components' => ['stats']]))->toBeFalse();
    });
});

describe('always kept', function () {
    it('keeps the event even when a rule drops it', function (array $event) {
        Jot::dropWhen(fn () => true);

        expect(keeps($event))->toBeTrue();
    })->with([
        'error' => [['error.type' => 'PDOException']],
        '5xx' => [['http.response.status_code' => 503]],
        'failed job' => [['job.status' => 'failed']],
        'slow' => [['duration_ms' => 1001]],
        'failed command' => [['console.exit_code' => 1]],
        'failed scheduled task' => [['schedule.exit_code' => 1]],
    ]);

    it('counts an event as slow only above slow_ms', function () {
        expect(keeps(['duration_ms' => 1000]))->toBeFalse();

        config(['jot.sampling.slow_ms' => 50]);

        expect(keeps(['duration_ms' => 51]))->toBeTrue();
    });

    it('keeps an event raised to info', function () {
        jot()->raise(LogLevel::INFO, 'vip');

        expect(jot()->event())->toMatchArray(['level.raised' => 'info', 'level.reason' => 'vip'])
            ->and(keeps(jot()->event()))->toBeTrue();
    });

    it('keeps the event when the cache store fails', function () {
        Cache::shouldReceive('add')->andThrow(new RuntimeException('Redis is down.'));

        expect(keeps(['error.type' => 'PDOException']))->toBeTrue();
    });

    it('reads a limit of 0 as no limit', function () {
        config(['jot.sampling.max_per_minute' => 0]);

        expect(keeps(['error.type' => 'PDOException']))->toBeTrue();
    });

    it('counts the limit again each minute', function () {
        $this->freezeTime();
        config(['jot.sampling.max_per_minute' => 1]);
        $error = ['error.type' => 'PDOException', 'http.route' => 'checkout'];

        expect(keeps($error))->toBeTrue()->and(keeps($error))->toBeFalse();

        $this->travel(1)->minutes();

        expect(keeps($error))->toBeTrue();
    });

    it('limits each level, job, command and scheduled task apart', function (array $first, array $second) {
        $this->freezeTime();
        config(['jot.sampling.max_per_minute' => 1]);

        expect(keeps($first))->toBeTrue()
            ->and(keeps($second))->toBeTrue()
            ->and(keeps($second))->toBeFalse();
    })->with([
        'level' => [['http.route' => 'checkout', 'duration_ms' => 5000], ['http.route' => 'checkout', 'level.raised' => 'warning']],
        'job' => [['error.type' => 'PDOException', 'job.name' => 'ImportJob'], ['error.type' => 'PDOException', 'job.name' => 'ExportJob']],
        'command' => [['console.exit_code' => 1, 'console.command' => 'users:create'], ['console.exit_code' => 1, 'console.command' => 'users:prune']],
        'scheduled task' => [['schedule.exit_code' => 1, 'schedule.task' => 'reports:send'], ['schedule.exit_code' => 1, 'schedule.task' => 'reports:prune']],
    ]);
});

it('samples a 400 at the client error rate, as a warning', function () {
    config(['jot.sampling.client_errors' => 1.0]);

    expect(keeps(['http.response.status_code' => 400]))->toBeTrue()
        ->and(Writer::level(['http.response.status_code' => 400]))->toBe(LogLevel::WARNING);
});

it('drops every Livewire 4 poll, even one that calls a method, unless it fails', function () {
    config(['jot.sampling.rate' => 1.0]);
    $poll = ['livewire.components' => ['stats'], 'livewire.calls' => ['stats::refresh'], 'livewire.poll' => true];

    expect(keeps($poll))->toBeFalse()
        ->and(keeps($poll + ['error.type' => 'RuntimeException']))->toBeTrue();
});

it('compares a value that is not a string as JSON, and never matches a missing field', function () {
    expect(Writer::matches(['user.id' => 0], ['user.id' => '0']))->toBeTrue()
        ->and(Writer::matches(['livewire.validation_failed' => true], ['livewire.validation_failed' => 'true']))->toBeTrue()
        ->and(Writer::matches(['error.retriable' => false], ['error.retriable' => 'false']))->toBeTrue()
        ->and(Writer::matches(['livewire.calls' => ['checkout::checkStatus']], ['livewire.calls' => '*::checkStatus*']))->toBeTrue()
        ->and(Writer::matches([], ['user.id' => '*']))->toBeFalse();
});
