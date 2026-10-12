<?php

namespace MarvelousDigital\WideEvents\Tests;

use Illuminate\Support\Facades\Route;
use MarvelousDigital\WideEvents\Enums\OutputShape;
use MarvelousDigital\WideEvents\Writer;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class RecordingTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}

function sentry(bool $logs = true): RecordingTransport
{
    config(['sentry.dsn' => 'https://public@example.com/1', 'sentry.enable_logs' => $logs]);
    app()->register(SentryServiceProvider::class);

    $transport = new RecordingTransport;
    app('sentry')->bindClient(ClientBuilder::create([
        'dsn' => 'https://public@example.com/1',
        'enable_logs' => $logs,
    ])->setTransport($transport)->getClient());

    return $transport;
}

/**
 * @return array<string, mixed> the attributes of each log Sentry received
 */
function sentryLogs(RecordingTransport $transport): array
{
    \Sentry\logger()->flush();

    return collect($transport->events)
        ->flatMap(fn (Event $event) => $event->getLogs())
        ->map(fn ($log) => $log->attributes()->toSimpleArray() + ['level' => (string) $log->getLevel()])
        ->all();
}

it('gives each event a level', function (array $event, string $level) {
    expect(Writer::level($event))->toBe($level);
})->with([
    'error' => [['error.type' => 'RuntimeException'], 'error'],
    '5xx' => [['http.response.status_code' => 503], 'error'],
    'failed job' => [['job.status' => 'failed'], 'error'],
    '4xx' => [['http.response.status_code' => 404], 'warning'],
    'failed command' => [['console.exit_code' => 1], 'warning'],
    'slow' => [['duration_ms' => 5000], 'notice'],
    'normal' => [['http.response.status_code' => 200, 'duration_ms' => 12], 'info'],
]);

it('skips an output below its level', function () {
    $paths = [storage_path('logs/jot-all.log'), storage_path('logs/jot-warnings.log')];
    array_map(fn ($path) => @unlink($path), $paths);
    config([
        'logging.channels.all' => ['driver' => 'single', 'path' => $paths[0]],
        'logging.channels.warnings' => ['driver' => 'single', 'path' => $paths[1]],
        'jot.outputs' => [
            ['channel' => 'all', 'shape' => OutputShape::Flat],
            ['channel' => 'warnings', 'shape' => OutputShape::Flat, 'level' => 'warning'],
        ],
    ]);
    Route::get('/', fn () => 'ok');
    Route::get('/missing', fn () => abort(404));

    $this->get('/');
    $this->get('/missing');

    expect(file_get_contents($paths[0]))->toContain('"url.path":"/"')->toContain('"url.path":"/missing"')
        ->and(file_get_contents($paths[1]))->not->toContain('"url.path":"/"')->toContain('.WARNING: jot');
});

it('writes one Sentry log per event, with each field as an attribute', function () {
    $transport = sentry();
    Route::get('/events/{event}', fn () => jot()->add('booking.event_id', 42) && 'ok');

    $this->get('/events/42');

    expect(sentryLogs($transport))->sequence(fn ($log) => $log->toMatchArray([
        'url.path' => '/events/42',
        'booking.event_id' => 42,
        'level' => 'info',
    ]));
});

it('keeps Sentry below its level', function () {
    config(['jot.sentry.level' => 'warning']);
    $transport = sentry();
    Route::get('/', fn () => 'ok');

    $this->get('/');

    expect(sentryLogs($transport))->toBe([]);
});

it('adds no Sentry output when the SDK has logs off', function () {
    sentry(logs: false);

    expect(array_column(app(Writer::class)->outputs(), 'channel'))->not->toContain('sentry_logs');
});

it('tags Sentry issues with the request and trace IDs', function () {
    $transport = sentry();
    Route::get('/', fn () => \Sentry\captureMessage('boom') && 'ok');

    $response = $this->get('/');

    expect($transport->events[0]->getTags())->toMatchArray([
        'request_id' => $response->headers->get('X-Request-Id'),
        'trace_id' => $response->headers->get('X-Request-Id'),
    ]);
});
