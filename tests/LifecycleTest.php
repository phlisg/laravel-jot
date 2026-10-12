<?php

namespace MarvelousDigital\WideEvents\Tests;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use MarvelousDigital\WideEvents\Contracts\Retriable;
use MarvelousDigital\WideEvents\Enums\OutputShape;
use MarvelousDigital\WideEvents\Facades\Jot;
use MarvelousDigital\WideEvents\Writer;
use RuntimeException;

class RetriableException extends RuntimeException implements Retriable {}

class SyncJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public bool $fail = false) {}

    public function handle(): void
    {
        jot()->add('import.rows', 3);

        if ($this->fail) {
            throw new RuntimeException('import broke');
        }
    }
}

class OuterJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        SyncJob::dispatch();
    }
}

class CreateUser extends Command
{
    protected $signature = 'users:create {email} {--admin} {--password=} {--team=default}';

    public function handle(): int
    {
        return 0;
    }
}

function emitted(): array
{
    return app(Writer::class)->faked;
}

function emittedBy(string $entrypoint): array
{
    return collect(emitted())->firstWhere('entrypoint', $entrypoint);
}

uses(WithConsoleEvents::class);

beforeEach(function () {
    Jot::fake();
    config(['queue.default' => 'sync']);
});

describe('http', function () {
    it('adds a caught exception passed to report() to the event', function () {
        Route::get('/sync', function () {
            try {
                throw new RuntimeException('CRM down');
            } catch (RuntimeException $e) {
                report($e);
            }

            return 'ok';
        });

        $this->get('/sync')->assertOk();

        expect(emittedBy('http'))->toMatchArray(['error.type' => RuntimeException::class, 'exception.message' => 'CRM down']);
    });

    it('writes one event at the end of the request', function () {
        Route::get('/events/{event}', fn () => jot()->add('booking.event_id', 42) && 'ok')->name('events.show');

        $response = $this->get('/events/42?token=secret');

        $event = emittedBy('http');

        expect($response->headers->get('X-Request-Id'))->toBe($event['trace_id'])
            ->and($event)->toMatchArray([
                'http.request.method' => 'GET',
                'url.path' => '/events/42',
                'http.route' => 'events.show',
                'http.response.status_code' => 200,
                'origin' => 'http',
                'booking.event_id' => 42,
            ])
            ->and($event)->toHaveKeys(['duration_ms', 'memory_peak_mb', 'db.query_count', 'app.locale']);
    });

    it('records the error and the user', function () {
        Jot::user(fn ($user) => ['user.plan' => 'enterprise']);
        Route::get('/boom', fn () => throw new RetriableException('nope', 7));

        $this->actingAs(new GenericUser(['id' => 7]))->get('/boom');

        expect(emittedBy('http'))->toMatchArray([
            'http.response.status_code' => 500,
            'error.type' => RetriableException::class,
            'exception.message' => 'nope',
            'error.code' => 7,
            'error.retriable' => true,
            'user.id' => 7,
            'user.plan' => 'enterprise',
        ]);
    });

    it('writes a failed login without the user', function () {
        Route::post('/login', fn () => event(new Failed('web', null, ['email' => 'jane@example.com'])) && 'no');

        $this->post('/login');

        expect(emittedBy('http'))->toMatchArray(['auth.result' => 'failed'])
            ->not->toHaveKey('user.id')
            ->and(json_encode(emittedBy('http')))->not->toContain('jane@example.com');
    });

    it('hashes the session ID', function () {
        Route::middleware('web')->get('/', fn () => 'ok');

        $this->get('/');

        expect(emittedBy('http')['session.hash'])->toHaveLength(16)
            ->not->toBe(session()->getId());
    });
});

describe('queue', function () {
    it('links a sync job to the request and keeps the request fields', function () {
        Route::get('/import', function () {
            jot()->add('import.file', 'a.csv');
            SyncJob::dispatch();

            return 'ok';
        });

        $this->get('/import');

        [$job, $http] = [emittedBy('queue'), emittedBy('http')];

        expect($job)->toMatchArray([
            'parent_span_id' => $http['span_id'],
            'trace_id' => $http['trace_id'],
            'origin' => 'http',
            'job.name' => SyncJob::class,
            'job.status' => 'processed',
            'import.rows' => 3,
        ])
            ->and($http)->toMatchArray(['import.file' => 'a.csv'])
            ->not->toHaveKey('import.rows');
    });

    it('writes a failed job with its error', function () {
        try {
            SyncJob::dispatchSync(fail: true);
        } catch (RuntimeException) {
        }

        expect(emittedBy('queue'))->toMatchArray(['job.status' => 'failed', 'error.type' => RuntimeException::class]);
    });
});

it('writes argument and option names, never their values', function () {
    app(Kernel::class)->registerCommand(new CreateUser);

    Artisan::call('users:create', ['email' => 'jane@example.com', '--admin' => true, '--password' => 'secret']);

    $event = emittedBy('console');

    expect($event)->toMatchArray([
        'console.command' => 'users:create',
        'console.arguments' => ['email'],
        'console.options' => ['--admin', '--password'],
        'console.exit_code' => 0,
    ])
        ->and(json_encode($event))->not->toContain('secret');
});

describe('sampling', function () {
    beforeEach(fn () => config(['jot.sampling.rate' => 0.0]));

    it('drops normal events', function () {
        Route::get('/', fn () => 'ok');

        $this->get('/');

        expect(emitted())->toBe([]);
    });

    it('keeps errors and events a rule matches', function () {
        Jot::keepWhen(fn (array $event) => ($event['url.path'] ?? null) === '/vip');
        Route::get('/vip', fn () => 'ok');
        Route::get('/boom', fn () => abort(503));

        $this->get('/vip');
        $this->get('/boom');

        expect(array_column(emitted(), 'url.path'))->toBe(['/vip', '/boom']);
    });
});

it('asserts on emitted events', function () {
    Route::get('/', fn () => 'ok');

    $this->get('/');

    Jot::assertEmitted(fn (array $event) => $event['url.path'] === '/');
});

describe('output shapes', function () {
    $event = ['timestamp' => 'now', 'trace_id' => 't1', 'service.version' => 'abc', 'http.request.method' => 'GET'];

    it('nests dotted keys', function () use ($event) {
        expect(OutputShape::Nested->apply($event)['http'])->toBe(['request' => ['method' => 'GET']]);
    });

    it('splits resource and attributes for OpenTelemetry', function () use ($event) {
        expect(OutputShape::Otel->apply($event))->toBe([
            'Timestamp' => 'now',
            'TraceId' => 't1',
            'Resource' => ['service.version' => 'abc'],
            'Attributes' => ['http.request.method' => 'GET'],
        ]);
    });
});

it('writes to the configured channel', function () {
    $path = storage_path('logs/jot-test.log');
    @unlink($path);
    app(Writer::class)->faked = null;
    config([
        'logging.channels.jot_test' => ['driver' => 'single', 'path' => $path],
        'jot.outputs' => [['channel' => 'jot_test', 'shape' => OutputShape::Flat]],
    ]);
    Route::get('/', fn () => 'ok');

    $this->get('/');

    expect(file_get_contents($path))->toContain('jot')->toContain('"url.path":"/"');
});

it('writes a scheduled task', function () {
    $task = app(Schedule::class)->call(fn () => null)->hourly()->description('reports:send');

    event(new ScheduledTaskStarting($task));
    jot()->add('report.count', 2);
    event(new ScheduledTaskFinished($task, 0.1));

    expect(emittedBy('schedule'))->toMatchArray([
        'origin' => 'schedule',
        'schedule.task' => 'reports:send',
        'schedule.expression' => '0 * * * *',
        'report.count' => 2,
    ]);
});

it('keeps the error of a job the worker fails', function () {
    $job = new \Illuminate\Queue\Jobs\SyncJob(app(), json_encode([
        'uuid' => 'job-1', 'displayName' => SyncJob::class, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => [],
    ]), 'redis', 'default');

    // Worker order: JobFailed first, then JobExceptionOccurred.
    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, new RuntimeException('import broke')));

    expect(emittedBy('queue'))->toMatchArray(['job.status' => 'failed', 'error.type' => RuntimeException::class]);
});

describe('ids across units of work', function () {
    it('points a nested job at its parent job, in the same trace', function () {
        Route::get('/import', fn () => OuterJob::dispatch() && 'ok');

        $this->get('/import');

        $http = emittedBy('http');
        [$inner, $outer] = array_values(array_filter(emitted(), fn ($e) => $e['entrypoint'] === 'queue'));

        expect($outer)->toMatchArray(['job.name' => OuterJob::class, 'parent_span_id' => $http['span_id']])
            ->and($inner)->toMatchArray(['job.name' => SyncJob::class, 'parent_span_id' => $outer['span_id']])
            ->and($inner['parent_span_id'])->not->toBe($inner['trace_id'])
            ->and([$outer['trace_id'], $inner['trace_id']])->each->toBe($http['trace_id'])
            ->and([$http, $outer, $inner])->each->not->toHaveKey('request_id');
    });

    it('gives a command called in a request its own span, in the same trace', function () {
        app(Kernel::class)->registerCommand(new CreateUser);
        Route::get('/users', fn () => Artisan::call('users:create', ['email' => 'jane@example.com']) === 0 ? 'ok' : 'no');

        $this->get('/users');

        [$http, $console] = [emittedBy('http'), emittedBy('console')];

        expect($console)->toMatchArray(['trace_id' => $http['trace_id'], 'parent_span_id' => $http['span_id'], 'origin' => 'http'])
            ->and($console['span_id'])->not->toBe($http['span_id'])
            ->and(Context::get('span_id'))->toBe($http['span_id']);
    });

    it('keeps an upstream trace down to the jobs', function () {
        config(['jot.trace.trust_incoming' => true]);
        Route::get('/import', fn () => SyncJob::dispatch() && 'ok');

        $this->get('/import', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

        [$http, $job] = [emittedBy('http'), emittedBy('queue')];

        expect($http)->toMatchArray(['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'parent_span_id' => '00f067aa0ba902b7'])
            ->and($job)->toMatchArray(['trace_id' => $http['trace_id'], 'parent_span_id' => $http['span_id']]);
    });
});
