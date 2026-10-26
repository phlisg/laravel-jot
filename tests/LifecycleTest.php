<?php

namespace Phlisg\WideEvents\Tests;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Jobs\SyncJob as QueuedJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use LogicException;
use Phlisg\WideEvents\Contracts\Retriable;
use Phlisg\WideEvents\Enums\OutputShape;
use Phlisg\WideEvents\Facades\Jot;
use Phlisg\WideEvents\Writer;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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

class SendReports extends Command
{
    protected $signature = 'reports:send';

    public function handle(): int
    {
        throw new RuntimeException('SMTP is down.');
    }
}

function emitted(): array
{
    return app(Writer::class)->faked;
}

function emittedBy(string $entrypoint): ?array
{
    return collect(emitted())->firstWhere('entrypoint', $entrypoint);
}

/**
 * A job as the worker hands it to the queue events.
 */
function workerJob(string $uuid = 'job-1'): QueuedJob
{
    return new QueuedJob(app(), json_encode([
        'uuid' => $uuid, 'displayName' => SyncJob::class, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => [],
    ]), 'redis', 'default');
}

function runCommand(string $command, int $exitCode = 0): void
{
    $input = new ArrayInput([]);

    event(new CommandStarting($command, $input, new NullOutput));
    event(new CommandFinished($command, $input, new NullOutput, $exitCode));
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

        expect(emittedBy('http'))->toMatchArray(['error.type' => RuntimeException::class, 'exception.message' => 'CRM down', 'error.count' => 1]);
    });

    it('cuts a long exception message to 2000 characters', function () {
        Route::get('/sync', function () {
            report(new RuntimeException(str_repeat('a', 5000)));

            return 'ok';
        });

        $this->get('/sync')->assertOk();

        expect(emittedBy('http')['exception.message'])->toBe(str_repeat('a', 2000).'...');
    });

    it('keeps the last reported exception, and counts them', function () {
        Route::get('/sync', function () {
            report(new RuntimeException('CRM down'));

            throw new LogicException('broken');
        });

        $this->get('/sync')->assertServerError();

        expect(emittedBy('http'))->toMatchArray(['error.type' => LogicException::class, 'error.count' => 2]);
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

        expect(emittedBy('http')['session.hash'])
            ->toBe(substr(hash_hmac('sha256', session()->getId(), config('app.key')), 0, 16));
    });

    it('writes a login made during the request, with the user', function () {
        Route::post('/login', function () {
            auth()->setUser($user = new GenericUser(['id' => 9]));
            event(new Login('web', $user, false));

            return 'ok';
        });

        $this->post('/login');

        expect(emittedBy('http'))->toMatchArray(['auth.result' => 'success', 'user.id' => 9]);
    });

    it('records no error for a 404', function () {
        $this->get('/missing')->assertNotFound();

        expect(emittedBy('http'))->toMatchArray(['http.response.status_code' => 404])->not->toHaveKey('error.type');
    });

    it('writes no error with the errors group switched off', function () {
        config(['jot.fields.errors' => false]);
        Route::get('/', fn () => report(new RuntimeException('CRM down')) ?? 'ok');

        $this->get('/');

        expect(emittedBy('http'))->not->toHaveKey('error.type');
    });

    it('skips the Jot::user() fields with the user group switched off', function () {
        config(['jot.fields.user' => false]);
        Jot::user(fn ($user) => ['user.plan' => 'enterprise']);
        Route::get('/', fn () => 'ok');

        $this->actingAs(new GenericUser(['id' => 7]))->get('/');

        expect(emittedBy('http'))->not->toHaveKeys(['user.id', 'user.plan']);
    });

    it('counts database queries, cache misses and HTTP client calls', function () {
        Http::fake(['*' => Http::response('ok')]);
        Route::get('/', function () {
            DB::select('select 1');
            Cache::get('missing');
            Http::get('https://example.test');
            Http::get('https://example.test');

            return 'ok';
        });

        $this->get('/');

        expect(emittedBy('http'))->toMatchArray(['db.query_count' => 1, 'cache.misses' => 1, 'http_client.count' => 2]);
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
            ->not->toHaveKey('import.file')
            ->and($http)->toMatchArray(['import.file' => 'a.csv'])
            ->not->toHaveKey('import.rows');
    });

    it('writes a failed job with its error', function () {
        try {
            SyncJob::dispatchSync(fail: true);
        } catch (RuntimeException) {
        }

        expect(emittedBy('queue'))->toMatchArray(['job.status' => 'failed', 'error.type' => RuntimeException::class, 'error.count' => 1]);
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
        ->and(json_encode($event))->not->toContain('secret')->not->toContain('jane@example.com');
});

it('writes the event of a command that boots a fresh app, as config:cache does', function (int $exitCode) {
    $app = app();
    $input = new ArrayInput([]);
    event(new CommandStarting('config:cache', $input, new NullOutput));

    // `new Application` makes itself the global container.
    Container::setInstance(new Container);

    try {
        $app['events']->dispatch(new CommandFinished('config:cache', $input, new NullOutput, $exitCode));
    } finally {
        Container::setInstance($app);
    }

    $app->terminate();

    expect(emittedBy('console'))->toMatchArray(['console.command' => 'config:cache', 'console.exit_code' => $exitCode]);
})->with([0, 1]);

it('writes the event of a command that never finished, when the app terminates', function () {
    event(new CommandStarting('reports:send', new ArrayInput([]), new NullOutput));
    jot()->error(new RuntimeException('SMTP is down.'));

    app()->terminate();

    expect(emittedBy('console'))->toMatchArray(['console.command' => 'reports:send', 'error.type' => RuntimeException::class])
        ->and(jot()->traceId())->toBeNull();
});

it('adds the exception of a command that throws to its event', function () {
    app(Kernel::class)->registerCommand(new SendReports);

    try {
        Artisan::call('reports:send');
    } catch (RuntimeException $e) {
        // As the console kernel does, once the command has ended.
        report($e);
    }

    app()->terminate();

    expect(emittedBy('console'))->toMatchArray(['console.exit_code' => 1, 'error.type' => RuntimeException::class]);
});

it('writes nothing for long-running commands, dev tools and Composer hooks', function (string $command) {
    runCommand($command, exitCode: 1);
    runCommand('reports:send', exitCode: 1);
    app()->terminate();

    expect(array_column(emitted(), 'console.command'))->toBe(['reports:send']);
})->with([
    'queue:work', 'queue:listen', 'horizon', 'horizon:work', 'octane:start', 'reverb:start', 'schedule:run', 'schedule:work',
    'pail', 'tinker', 'help', 'list', 'package:discover', 'vendor:publish', 'ide-helper:models', 'boost:mcp',
    'filament:upgrade', 'test', 'jot:query', 'jot:env',
]);

it('writes nothing with Jot switched off', function () {
    config(['jot.enabled' => false]);
    app(Kernel::class)->registerCommand(new CreateUser);
    Route::get('/', fn () => 'ok');
    $task = app(Schedule::class)->call(fn () => null)->description('reports:send');

    $response = $this->get('/');
    SyncJob::dispatch();
    Artisan::call('users:create', ['email' => 'jane@example.com']);
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));

    expect(emitted())->toBe([])
        ->and($response->headers->has('X-Request-Id'))->toBeFalse();
});

it('writes nothing for an event that never started', function () {
    jot()->add('payment.provider', 'stripe');

    expect(jot()->emit())->toBe([])
        ->and(emitted())->toBe([])
        ->and(jot()->initialize(Request::create('/'))->down())->toHaveKey('payment.provider', 'stripe')
        ->and(emitted())->toHaveCount(1);
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

it('drops events a rule matches, but keeps their errors', function () {
    Jot::dropWhen(fn (array $event) => str_starts_with($event['url.path'] ?? '', '/poll'));
    Jot::keepWhen(fn () => true);
    Route::get('/poll', fn () => 'ok');
    Route::get('/poll/boom', fn () => abort(503));
    Route::get('/', fn () => 'ok');

    $this->get('/poll');
    $this->get('/poll/boom');
    $this->get('/');

    expect(array_column(emitted(), 'url.path'))->toBe(['/poll/boom', '/']);
});

it('asserts on emitted events', function () {
    Route::get('/', fn () => 'ok');

    $this->get('/');

    Jot::assertEmitted(fn (array $event) => $event['url.path'] === '/');
});

describe('output shapes', function () {
    $event = ['timestamp' => 'now', 'trace_id' => 't1', 'span_id' => 's1', 'service.version' => 'abc', 'http.request.method' => 'GET'];

    it('nests dotted keys', function () use ($event) {
        expect(OutputShape::Nested->apply($event)['http'])->toBe(['request' => ['method' => 'GET']]);
    });

    it('splits resource and attributes for OpenTelemetry', function () use ($event) {
        expect(OutputShape::Otel->apply($event))->toBe([
            'Timestamp' => 'now',
            'TraceId' => 't1',
            'SpanId' => 's1',
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

it('writes to the other outputs when one fails, even if reporting the failure fails too', function () {
    $path = storage_path('logs/jot-test.log');
    @unlink($path);
    app(Writer::class)->faked = null;
    config([
        'logging.channels.jot_test' => ['driver' => 'single', 'path' => $path],
        'jot.outputs' => [
            ['channel' => 'jot_test', 'level' => 'not-a-level'],
            ['channel' => 'jot_test', 'shape' => OutputShape::Flat],
        ],
    ]);
    app(ExceptionHandler::class)->reportable(fn (ValueError $e) => throw new RuntimeException('The app log is down too.'));
    Route::get('/', fn () => 'ok');

    $this->get('/')->assertOk();

    expect(file_get_contents($path))->toContain('"url.path":"/"');
});

it('writes the IDs once in the default file', function () {
    $path = storage_path('logs/jot-'.date('Y-m-d').'.log');
    @unlink($path);
    app(Writer::class)->faked = null;
    config(['jot.outputs' => [['channel' => null]]]);
    Route::get('/', fn () => 'ok');
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
        $logged[] = $log->context['trace_id'] ?? null;
    });

    $this->get('/');

    $line = json_decode(file_get_contents($path), true);

    expect($line['context'])->toHaveKey('trace_id')
        ->and($line)->not->toHaveKey('extra')
        ->and($logged)->toBe([$line['context']['trace_id']]);
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

it('writes a failed scheduled task with its error and exit code, whatever the rate', function () {
    config(['jot.sampling.rate' => 0.0]);
    $task = app(Schedule::class)->call(fn () => null)->description('reports:send');

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    event(new ScheduledTaskFailed($task, new RuntimeException('SMTP is down.')));

    expect(emittedBy('schedule'))->toMatchArray(['schedule.exit_code' => 1, 'error.type' => RuntimeException::class]);
});

it('keeps the error of a job the worker fails, and off the event that ran the worker', function () {
    jot()->initialize(Request::create('/'));
    $job = workerJob();

    // Worker order: JobFailed first, then JobExceptionOccurred.
    event(new JobProcessing('redis', $job));
    event(new JobFailed('redis', $job, $e = new RuntimeException('import broke')));
    event(new JobExceptionOccurred('redis', $job, $e));

    expect(emittedBy('queue'))->toMatchArray(['job.status' => 'failed', 'error.type' => RuntimeException::class, 'error.count' => 1])
        ->and(jot()->event())->not->toHaveKey('error.type');
});

it('writes a job released for a retry, with its error', function () {
    $job = workerJob();

    event(new JobProcessing('redis', $job));
    event(new JobExceptionOccurred('redis', $job, new RuntimeException('timeout')));
    event(new JobReleasedAfterException('redis', $job, 10));

    expect(emittedBy('queue'))->toMatchArray(['job.status' => 'released', 'error.type' => RuntimeException::class]);
});

it('ignores the events of a job it never started', function () {
    $input = new ArrayInput([]);
    event(new CommandStarting('reports:send', $input, new NullOutput));

    event(new JobFailed('redis', $job = workerJob(), new RuntimeException('import broke')));
    event(new JobProcessed('redis', $job));

    expect(emitted())->toBe([]);

    event(new CommandFinished('reports:send', $input, new NullOutput, 0));

    expect(emittedBy('console'))->not->toHaveKey('error.type');
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

    it('restores the hidden Context after a sync job', function () {
        Route::get('/', function () {
            Context::addHidden('tenant', 'acme');
            SyncJob::dispatch();

            return Context::getHidden('tenant');
        });

        expect($this->get('/')->getContent())->toBe('acme');
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
