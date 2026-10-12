<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use MarvelousDigital\WideEvents\Jot;
use Symfony\Component\HttpFoundation\Response;

class DemoCommand extends Command
{
    protected $signature = 'jot:demo {scenario? : A scenario name, or "all"}';

    protected $description = 'Run a jot scenario in-process and print the events it wrote';

    protected const SCENARIOS = [
        'booking' => 'Paid event booking: event page, details, Stripe payment, webhook',
        'job-failed' => 'A request queues a job, a worker runs it and the job fails',
        'job-sync' => 'A request runs a sync job, the job event points back to the request',
        'login' => 'Login with the right password',
        'login-failed' => 'Login with a wrong password',
        'error' => 'An exception, answered with a 500',
        'query' => 'jot:query on the trace of a failed job, then on the job span',
    ];

    /** @var array<string, string> */
    protected array $cookies = [];

    public function handle(): int
    {
        $scenario = $this->argument('scenario');
        $names = $scenario === 'all' ? array_keys(self::SCENARIOS) : [$scenario];

        if (! $scenario || array_diff($names, array_keys(self::SCENARIOS))) {
            $this->table(['Scenario', 'What it does'], array_map(null, array_keys(self::SCENARIOS), self::SCENARIOS));

            return $scenario ? self::FAILURE : self::SUCCESS;
        }

        $log = storage_path('logs/jot-'.date('Y-m-d').'.log');

        foreach ($names as $name) {
            $this->components->info($name);
            $this->cookies = [];
            clearstatcache();
            $offset = is_file($log) ? filesize($log) : 0;

            $this->{'run'.Str::studly($name)}();

            // jot:query prints the events itself.
            if ($name !== 'query') {
                $this->printEvents($log, $offset);
            }
        }

        $this->line("Log file: $log");

        return self::SUCCESS;
    }

    protected function runBooking(): void
    {
        $this->send('GET', '/events/42');
        $this->send('POST', '/events/42/details', ['name' => 'Ada', 'email' => 'ada@example.com']);
        $intent = json_decode($this->send('POST', '/events/42/pay')->getContent())->intent_id;

        // Stripe's servers carry no session cookie.
        $this->cookies = [];
        $this->send('POST', '/stripe/webhook', ['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => $intent]]]);
    }

    protected function runJobFailed(): void
    {
        $this->send('POST', '/jobs/failing');
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true]);
        $this->line('queue:work --stop-when-empty');
    }

    protected function runJobSync(): void
    {
        $this->send('GET', '/jobs/sync');
    }

    protected function runLogin(): void
    {
        $this->send('POST', '/login', ['email' => 'demo@example.com', 'password' => 'password']);
    }

    protected function runLoginFailed(): void
    {
        $this->send('POST', '/login', ['email' => 'demo@example.com', 'password' => 'wrong']);
    }

    protected function runError(): void
    {
        $this->send('GET', '/boom');
    }

    protected function runQuery(): void
    {
        $trace = $this->send('POST', '/jobs/failing')->headers->get('X-Request-Id');
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true]);

        $this->line("jot:query {$trace}");
        $this->call('jot:query', ['id' => $trace]);

        $job = collect(file(storage_path('logs/jot-'.date('Y-m-d').'.log')))
            ->map(fn ($line) => json_decode($line, true)['context'])
            ->last(fn ($event) => $event['trace_id'] === $trace && $event['entrypoint'] === 'queue');

        $this->newLine();
        $this->line("jot:query {$job['span_id']}");
        $this->call('jot:query', ['id' => $job['span_id']]);
    }

    /**
     * One request through the HTTP kernel, with the state a new PHP process would start with.
     */
    protected function send(string $method, string $uri, array $data = []): Response
    {
        $this->laravel->forgetInstance(Jot::class);
        Context::flush();
        Auth::forgetGuards();
        session()->flush();

        $kernel = $this->laravel->make(Kernel::class);
        $request = Request::create($uri, $method, $data, $this->cookies);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        $this->line("$method $uri -> {$response->getStatusCode()}");

        return $response;
    }

    protected function printEvents(string $log, int $offset): void
    {
        $lines = is_file($log) ? file_get_contents($log, offset: $offset) : '';

        foreach (array_filter(explode("\n", $lines)) as $line) {
            $this->line(json_encode(json_decode($line, true)['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}
