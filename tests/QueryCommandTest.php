<?php

namespace MarvelousDigital\WideEvents\Tests;

use Illuminate\Support\Facades\Route;
use MarvelousDigital\WideEvents\Enums\OutputShape;
use MarvelousDigital\WideEvents\Writer;

/**
 * @return array{string, string} the trace ID, and the span ID of the job
 */
function requestWithJob(): array
{
    Route::get('/import', fn () => SyncJob::dispatch(fail: true) && 'ok')->name('import');

    $trace = test()->get('/import')->headers->get('X-Request-Id');
    preg_match('/"span_id":"([0-9a-f]{16})","parent_span_id"/', file_get_contents(storage_path('logs/jot-query.log')), $match);

    return [$trace, $match[1]];
}

beforeEach(function () {
    @unlink(storage_path('logs/jot-query.log'));
    config([
        'queue.default' => 'sync',
        'logging.channels.query' => ['driver' => 'single', 'path' => storage_path('logs/jot-query.log')],
        'jot.outputs' => [['channel' => 'query', 'shape' => OutputShape::Flat]],
    ]);
});

it('prints a trace as a tree', function () {
    [$trace, $job] = requestWithJob();

    $this->artisan('jot:query', ['id' => $trace])
        ->expectsOutputToContain('GET import 500')
        ->expectsOutputToContain("└─ queue  {$job}  SyncJob failed")
        ->assertSuccessful();
});

it('prints every field of a span, in its trace', function () {
    [$trace, $job] = requestWithJob();

    $this->artisan('jot:query', ['id' => $job])
        ->expectsOutputToContain('GET import 500')
        ->expectsOutputToContain($trace)
        ->expectsOutputToContain('job.status')
        ->assertSuccessful();
});

it('reads the OpenTelemetry shape', function () {
    config(['jot.outputs.0.shape' => OutputShape::Otel]);
    Route::get('/', fn () => 'ok');

    $trace = $this->get('/')->headers->get('X-Request-Id');

    $this->artisan('jot:query', ['id' => $trace])->expectsOutputToContain('GET / 200')->assertSuccessful();
});

it('fails on an unknown or malformed ID', function () {
    $this->artisan('jot:query', ['id' => str_repeat('0', 32)])->assertFailed();
    $this->artisan('jot:query', ['id' => 'req_123'])->assertExitCode(2);
});

it('names the outputs it cannot read', function () {
    config(['jot.outputs' => [['channel' => 'stderr']]]);

    $this->artisan('jot:query', ['id' => str_repeat('0', 32)])
        ->expectsOutputToContain('Also written to the stderr channel')
        ->assertFailed();
});

it('reads an empty channel as the default Jot file', function () {
    config(['jot.outputs' => [['channel' => '']]]);

    expect(app(Writer::class)->outputs())->toBe([['channel' => null]]);
});
