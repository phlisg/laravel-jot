<?php

namespace MarvelousDigital\WideEvents\Tests;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use LogicException;
use MarvelousDigital\WideEvents\Commands\TailCommand;
use MarvelousDigital\WideEvents\Enums\OutputShape;
use MarvelousDigital\WideEvents\Writer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Runs one queued closure per poll of --follow, then stops it. */
class FollowedTailCommand extends TailCommand
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
class RefreshedTailCommand extends TailCommand
{
    /** @var list<Closure(): void> */
    public static array $reads = [];

    protected function recent(array $files, int $limit): array
    {
        (array_shift(static::$reads) ?? fn () => null)();

        return parent::recent($files, $limit);
    }
}

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

it('reads files old enough for --since', function () {
    tailed('old', ['timestamp' => now()->subHours(36)->toIso8601String()]);
    touch(storage_path('logs/jot-tail.log'), now()->subHours(36)->getTimestamp());

    expect(tail())->toBe([])
        ->and(tail(['--since' => '2d']))->toBe(['old']);
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

it('browses the events in a table', function () {
    tailed('older', ['span_id' => 'aaaaaaaaaaaaaaaa', 'timestamp' => now()->subMinute()->toIso8601String()]);
    tailed('newer', ['span_id' => 'bbbbbbbbbbbbbbbb', 'user.id' => 7]);

    Prompt::fake([Key::DOWN, Key::ENTER]);

    $this->artisan('jot:tail', ['-i' => true])
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('Recent events')
        ->expectsOutputToContain('aaaaaaaaaaaaaaaa')
        ->doesntExpectOutputToContain('user.id')
        ->assertSuccessful();
});

it('picks another event from the trace', function () {
    $trace = str_repeat('t', 32);
    tailed('parent', ['trace_id' => $trace, 'span_id' => 'aaaaaaaaaaaaaaaa', 'user.id' => 7, 'timestamp' => now()->subMinute()->toIso8601String()]);
    tailed('child', ['trace_id' => $trace, 'span_id' => 'bbbbbbbbbbbbbbbb', 'parent_span_id' => 'aaaaaaaaaaaaaaaa']);

    Prompt::fake([Key::ENTER]);

    $this->artisan('jot:tail', ['-i' => true])
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
    tailed('older', ['span_id' => 'aaaaaaaaaaaaaaaa', 'timestamp' => now()->subMinute()->toIso8601String()]);
    tailed('newer', ['span_id' => 'bbbbbbbbbbbbbbbb']);

    // Mockery hands out the queued keys in order, across both tables.
    Prompt::fake([Key::DOWN, Key::ENTER, Key::ENTER]);

    $this->artisan('jot:tail', ['-i' => true])
        ->expectsChoice('Next', 'list', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('aaaaaaaaaaaaaaaa')
        ->expectsOutputToContain('older')
        ->expectsOutputToContain('bbbbbbbbbbbbbbbb')
        ->expectsOutputToContain('newer')
        ->assertSuccessful();
});

it('warns instead of browsing when nothing matches', function () {
    tailed('home');

    $this->artisan('jot:tail', ['-i' => true, '--where' => ['http.route=nope']])
        ->expectsOutputToContain('No matching event')
        ->assertFailed();
});

it('refuses a --where without a value', function () {
    $this->artisan('jot:tail', ['--where' => ['user.id']])->expectsOutputToContain('Expected key=value')->assertExitCode(2);
});

it('follows new lines, and the next daily file whole', function () {
    $today = storage_path('logs/jot-tail-'.now()->format('Y-m-d').'.log');
    $yesterday = storage_path('logs/jot-tail-'.now()->subDay()->format('Y-m-d').'.log');
    @unlink($today);
    @unlink($yesterday);
    config(['logging.channels.tail' => ['driver' => 'daily', 'path' => storage_path('logs/jot-tail.log')]]);

    tailed('old');
    Log::forgetChannel('tail');
    rename($today, $yesterday);
    touch($yesterday, now()->subMinute()->getTimestamp());

    FollowedTailCommand::$polls = [fn () => tailed('rolled'), fn () => tailed('appended')];
    $command = app(FollowedTailCommand::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;

    expect(fn () => $command->run(new ArrayInput(['--follow' => true]), $output))->toThrow(LogicException::class, 'stop following');

    preg_match_all('/GET (\S+)/', $output->fetch(), $matches);
    expect($matches[1])->toBe(['old', 'rolled', 'appended']);

    unlink($today);
    unlink($yesterday);
});

it('refuses --interactive with --follow or --json', function () {
    $this->artisan('jot:tail', ['-i' => true, '--json' => true])->assertExitCode(2);
    $this->artisan('jot:tail', ['-i' => true, '--follow' => true])->assertExitCode(2);
});

it('prints the plain list without an interactive input', function () {
    tailed('home');

    $this->artisan('jot:tail', ['-i' => true, '--no-interaction' => true])->expectsOutputToContain('GET home 200')->assertSuccessful();
});

it('searches the table by span ID, r typed as a letter', function () {
    tailed('older', ['span_id' => 'aaaarrrraaaarrrr', 'timestamp' => now()->subMinute()->toIso8601String()]);
    tailed('newer', ['span_id' => 'bbbbbbbbbbbbbbbb']);

    Prompt::fake(['/', 'A', 'R', 'r', Key::ENTER, Key::ENTER]);

    $this->artisan('jot:tail', ['-i' => true])
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('aaaarrrraaaarrrr')
        ->doesntExpectOutputToContain('bbbbbbbbbbbbbbbb')
        ->assertSuccessful();
});

it('reloads the events with r, the cursor kept on its span', function () {
    tailed('older', ['span_id' => 'aaaaaaaaaaaaaaaa', 'timestamp' => now()->subMinute()->toIso8601String()]);
    tailed('newer', ['span_id' => 'bbbbbbbbbbbbbbbb', 'timestamp' => now()->subSeconds(30)->toIso8601String()]);
    RefreshedTailCommand::$reads = [fn () => null, fn () => tailed('newest', ['span_id' => 'cccccccccccccccc'])];
    app(Kernel::class)->registerCommand(app(RefreshedTailCommand::class));

    Prompt::fake([Key::DOWN, 'r', Key::ENTER]);

    $this->artisan('jot:tail', ['-i' => true])
        ->expectsChoice('Next', 'quit', ['list' => 'Back to the list', 'trace' => 'Show the trace', 'quit' => 'Quit'])
        ->expectsOutputToContain('newest')
        ->expectsOutputToContain('aaaaaaaaaaaaaaaa')
        ->doesntExpectOutputToContain('cccccccccccccccc')
        ->assertSuccessful();
});
