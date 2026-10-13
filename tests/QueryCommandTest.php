<?php

namespace MarvelousDigital\WideEvents\Tests;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use LogicException;
use MarvelousDigital\WideEvents\Commands\QueryCommand;
use MarvelousDigital\WideEvents\Enums\OutputShape;
use MarvelousDigital\WideEvents\Writer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Jobs\ChargeCard;

/** Runs as if the output were a terminal. */
class InteractiveQueryCommand extends QueryCommand
{
    protected function atTerminal(): bool
    {
        return $this->input->isInteractive();
    }
}

/** Runs one queued closure per poll of --follow, then stops it. */
class FollowedQueryCommand extends QueryCommand
{
    /** @var list<Closure(): void> */
    public static array $polls = [];

    protected function pause(): void
    {
        $poll = array_shift(static::$polls) ?? throw new LogicException('stop following');
        $poll();
    }
}

/** Runs one queued closure before each read of the files. */
class RefreshedQueryCommand extends InteractiveQueryCommand
{
    /** @var list<Closure(): void> */
    public static array $reads = [];

    protected function recent(array $files, int $limit, Closure $filter): array
    {
        (array_shift(static::$reads) ?? fn () => null)();

        return parent::recent($files, $limit, $filter);
    }
}

function atTerminal(): void
{
    app(Kernel::class)->registerCommand(app(InteractiveQueryCommand::class));
}

/**
 * @return array{string, string} the trace ID, and the span ID of the job
 */
function requestWithJob(): array
{
    Route::get('/import', fn () => ChargeCard::dispatch(42) && 'ok')->name('import');

    $trace = test()->get('/import')->headers->get('X-Request-Id');
    preg_match('/"span_id":"([0-9a-f]{16})","parent_span_id"/', file_get_contents(storage_path('logs/jot-query.log')), $match);

    return [$trace, $match[1]];
}

function logged(string $route, array $fields = []): void
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
 * @return list<string> the routes listed, in order
 */
function listed(array $options = []): array
{
    Artisan::call('jot:query', $options);
    preg_match_all('/GET (\S+)/', Artisan::output(), $matches);

    return $matches[1];
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
        ->expectsOutputToContain("└─ queue  {$job}  ChargeCard failed")
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

it('picks an event from the trace at a terminal', function () {
    [$trace, $job] = requestWithJob();
    atTerminal();

    $this->artisan('jot:query', ['id' => $trace])
        ->expectsQuestion("Trace {$trace}, 2 event(s)", $job)
        ->expectsChoice('Next', 'trace', ['trace' => 'Back to the trace', 'quit' => 'Quit'])
        ->expectsQuestion("Trace {$trace}, 2 event(s)", $job)
        ->expectsChoice('Next', 'quit', ['trace' => 'Back to the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('job.status')
        ->doesntExpectOutputToContain('Run jot:query with a span ID')
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

it('refuses the list options with an ID', function () {
    $this->artisan('jot:query', ['id' => str_repeat('0', 32), '--errors' => true, '--since' => '1h'])
        ->expectsOutputToContain('These options only work without an ID: --errors, --since.')
        ->assertExitCode(2);
    $this->artisan('jot:query', ['id' => str_repeat('0', 32), '--limit' => 5])->assertExitCode(2);
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

it('prints the last events, newest last', function () {
    foreach (['a', 'b', 'c', 'd'] as $i => $route) {
        logged($route, ['timestamp' => now()->addSeconds($i)->toIso8601String()]);
    }

    expect(listed(['--limit' => 3]))->toBe(['b', 'c', 'd']);

    $this->artisan('jot:query')
        ->expectsOutputToContain('http  ')
        ->expectsOutputToContain('Run jot:query <trace_id> for a trace')
        ->assertSuccessful();
});

it('filters on fields, with wildcards', function () {
    logged('bookings.store', ['user.id' => 7]);
    logged('bookings.show', ['user.id' => 8]);
    logged('home');

    expect(listed(['--where' => ['user.id=7']]))->toBe(['bookings.store'])
        ->and(listed(['--where' => ['http.route=bookings.*']]))->toBe(['bookings.store', 'bookings.show'])
        ->and(listed(['--where' => ['http.route=bookings.*', 'user.id=8']]))->toBe(['bookings.show'])
        ->and(listed(['--where' => ['user.id=7,8']]))->toBe(['bookings.store', 'bookings.show']);
});

it('keeps only errors, or only slow events', function () {
    logged('ok');
    logged('server', ['http.response.status_code' => 503]);
    logged('thrown', ['error.type' => 'RuntimeException']);
    logged('slow', ['duration_ms' => 1500]);

    expect(listed(['--errors' => true]))->toBe(['server', 'thrown'])
        ->and(listed(['--slow' => true]))->toBe(['slow']);
});

it('keeps only events since a duration', function () {
    logged('old', ['timestamp' => now()->subHours(2)->toIso8601String()]);
    logged('new');

    expect(listed(['--since' => '1h']))->toBe(['new']);

    $this->artisan('jot:query', ['--since' => 'an hour'])->assertExitCode(2);
});

it('reads files old enough for --since', function () {
    logged('old', ['timestamp' => now()->subHours(36)->toIso8601String()]);
    touch(storage_path('logs/jot-query.log'), now()->subHours(36)->getTimestamp());

    expect(listed())->toBe([])
        ->and(listed(['--since' => '2d']))->toBe(['old']);
});

it('prints JSON lines', function () {
    logged('home', ['span_id' => 'aaaabbbbccccdddd']);

    Artisan::call('jot:query', ['--json' => true]);

    expect(json_decode(trim(Artisan::output()), true))->toMatchArray(['span_id' => 'aaaabbbbccccdddd', 'http.route' => 'home']);
});

it('warns when nothing matches', function () {
    logged('home');

    expect(Artisan::call('jot:query', ['--where' => ['http.route=nope']]))->toBe(1)
        ->and(Artisan::output())->toContain('No matching event')->toContain('jot-query.log');
});

it('browses the events in a table', function () {
    atTerminal();
    logged('older', ['span_id' => 'aaaaaaaaaaaaaaaa', 'timestamp' => now()->subMinute()->toIso8601String()]);
    logged('newer', ['span_id' => 'bbbbbbbbbbbbbbbb', 'user.id' => 7]);

    Prompt::fake([Key::DOWN, Key::ENTER]);

    $this->artisan('jot:query')
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('Recent events')
        ->expectsOutputToContain('aaaaaaaaaaaaaaaa')
        ->doesntExpectOutputToContain('user.id')
        ->assertSuccessful();
});

it('picks another event from the trace', function () {
    atTerminal();
    $trace = str_repeat('t', 32);
    logged('parent', ['trace_id' => $trace, 'span_id' => 'aaaaaaaaaaaaaaaa', 'user.id' => 7, 'timestamp' => now()->subMinute()->toIso8601String()]);
    logged('child', ['trace_id' => $trace, 'span_id' => 'bbbbbbbbbbbbbbbb', 'parent_span_id' => 'aaaaaaaaaaaaaaaa']);

    Prompt::fake([Key::ENTER]);

    $this->artisan('jot:query')
        ->expectsChoice('Next', 'trace', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsChoice("Trace {$trace}, 2 event(s)", 'aaaaaaaaaaaaaaaa', [
            'aaaaaaaaaaaaaaaa' => 'http  aaaaaaaaaaaaaaaa  GET parent 200  5ms',
            'bbbbbbbbbbbbbbbb' => '└─ http  bbbbbbbbbbbbbbbb  GET child 200  5ms ◀',
        ])
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('user.id')
        ->assertSuccessful();
});

it('goes back to the list to pick another event', function () {
    atTerminal();
    logged('older', ['span_id' => 'aaaaaaaaaaaaaaaa', 'timestamp' => now()->subMinute()->toIso8601String()]);
    logged('newer', ['span_id' => 'bbbbbbbbbbbbbbbb']);

    // Mockery hands out the queued keys in order, across both tables.
    Prompt::fake([Key::DOWN, Key::ENTER, Key::ENTER]);

    $this->artisan('jot:query')
        ->expectsChoice('Next', 'list', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('aaaaaaaaaaaaaaaa')
        ->expectsOutputToContain('older')
        ->expectsOutputToContain('bbbbbbbbbbbbbbbb')
        ->expectsOutputToContain('newer')
        ->assertSuccessful();
});

it('warns instead of browsing when nothing matches', function () {
    atTerminal();
    logged('home');

    $this->artisan('jot:query', ['--where' => ['http.route=nope']])
        ->expectsOutputToContain('No matching event')
        ->assertFailed();
});

it('refuses a --where without a value', function () {
    $this->artisan('jot:query', ['--where' => ['user.id']])->expectsOutputToContain('Expected key=value')->assertExitCode(2);
});

it('follows new lines, and the next daily file whole', function () {
    $today = storage_path('logs/jot-query-'.now()->format('Y-m-d').'.log');
    $yesterday = storage_path('logs/jot-query-'.now()->subDay()->format('Y-m-d').'.log');
    @unlink($today);
    @unlink($yesterday);
    config(['logging.channels.query' => ['driver' => 'daily', 'path' => storage_path('logs/jot-query.log')]]);

    logged('old');
    Log::forgetChannel('query');
    rename($today, $yesterday);
    touch($yesterday, now()->subMinute()->getTimestamp());

    FollowedQueryCommand::$polls = [fn () => logged('rolled'), fn () => logged('appended')];
    $command = app(FollowedQueryCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;

    expect(fn () => $command->run(new ArrayInput(['--follow' => true]), $output))->toThrow(LogicException::class, 'stop following');

    preg_match_all('/GET (\S+)/', $output->fetch(), $matches);
    expect($matches[1])->toBe(['old', 'rolled', 'appended']);

    unlink($today);
    unlink($yesterday);
});

it('prints JSON lines instead of the table at a terminal', function () {
    atTerminal();
    logged('home', ['span_id' => 'aaaabbbbccccdddd']);

    $this->artisan('jot:query', ['--json' => true])
        ->expectsOutputToContain('"span_id":"aaaabbbbccccdddd"')
        ->doesntExpectOutputToContain('Recent events')
        ->assertSuccessful();
});

it('prints the plain list without an interactive input', function () {
    atTerminal();
    logged('home');

    $this->artisan('jot:query', ['--no-interaction' => true])->expectsOutputToContain('GET home 200')->assertSuccessful();
});

it('prints the plain list when the output is not a terminal', function () {
    logged('home');

    $this->artisan('jot:query')
        ->expectsOutputToContain('GET home 200')
        ->doesntExpectOutputToContain('Recent events')
        ->assertSuccessful();
});

it('searches the table by span ID, r typed as a letter', function () {
    atTerminal();
    logged('older', ['span_id' => 'aaaarrrraaaarrrr', 'timestamp' => now()->subMinute()->toIso8601String()]);
    logged('newer', ['span_id' => 'bbbbbbbbbbbbbbbb']);

    Prompt::fake(['/', 'A', 'R', 'r', Key::ENTER, Key::ENTER]);

    $this->artisan('jot:query')
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('aaaarrrraaaarrrr')
        ->doesntExpectOutputToContain('bbbbbbbbbbbbbbbb')
        ->assertSuccessful();
});

it('reloads the events with r, the cursor kept on its span', function () {
    logged('older', ['span_id' => 'aaaaaaaaaaaaaaaa', 'timestamp' => now()->subMinute()->toIso8601String()]);
    logged('newer', ['span_id' => 'bbbbbbbbbbbbbbbb', 'timestamp' => now()->subSeconds(30)->toIso8601String()]);
    RefreshedQueryCommand::$reads = [fn () => null, fn () => logged('newest', ['span_id' => 'cccccccccccccccc'])];
    app(Kernel::class)->registerCommand(app(RefreshedQueryCommand::class));

    Prompt::fake([Key::DOWN, 'r', Key::ENTER]);

    $this->artisan('jot:query')
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('newest')
        ->expectsOutputToContain('aaaaaaaaaaaaaaaa')
        ->doesntExpectOutputToContain('cccccccccccccccc')
        ->assertSuccessful();
});
