<?php

namespace MarvelousDigital\WideEvents;

use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use MarvelousDigital\WideEvents\Sections\ConsoleSection;
use MarvelousDigital\WideEvents\Sections\JobSection;
use MarvelousDigital\WideEvents\Sections\PerformanceSection;
use MarvelousDigital\WideEvents\Sections\RequestSection;
use MarvelousDigital\WideEvents\Sections\ScheduleSection;
use MarvelousDigital\WideEvents\Sections\UserSection;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Singleton: starts and emits the job, command and scheduled task events, and fills the user and performance sections.
 */
class Lifecycle
{
    /** @var list<array{object, Jot, array, array}> the unit of work, then what it replaced */
    protected array $stack = [];

    public function register(Dispatcher $events): void
    {
        if (config('jot.queue.enabled', true)) {
            $events->listen(JobProcessing::class, $this->startJob(...));
            $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->endJob($e->job, 'processed'));
            // The worker fires JobFailed before JobExceptionOccurred, the sync queue after: listen to both.
            $events->listen(JobFailed::class, function (JobFailed $e) {
                $this->top() === $e->job && jot()->error($e->exception);
                $this->endJob($e->job, 'failed');
            });
            $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $this->top() === $e->job && jot()->error($e->exception));
            $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $e) => $this->endJob($e->job, 'released'));
        }

        if (config('jot.console.enabled', true)) {
            $events->listen(CommandStarting::class, $this->startCommand(...));
            $events->listen(CommandFinished::class, $this->endCommand(...));
        }

        if (config('jot.console.schedule', true)) {
            $events->listen(ScheduledTaskStarting::class, $this->startTask(...));
            $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => $this->endTask($e->task));
            $events->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $e) {
                jot()->error($e->exception);
                $this->endTask($e->task);
            });
        }

        if (config('jot.fields.auth', true)) {
            $events->listen(Login::class, fn () => $this->section(UserSection::class, fn (UserSection $user) => $user->authResult = 'success'));
            $events->listen(Failed::class, fn () => $this->section(UserSection::class, fn (UserSection $user) => $user->authResult = 'failed'));
        }

        $this->registerPerformance($events);
        (new LivewireListener)->register();

        // A command that throws never fires CommandFinished.
        app()->terminating(function () {
            while ($unit = $this->top()) {
                $this->pop($unit, fn () => null);
            }
        });
    }

    protected function registerPerformance(Dispatcher $events): void
    {
        $on = fn (string $group) => config('jot.fields.performance') && config("jot.fields.performance.$group", true);

        if ($on('db')) {
            $events->listen(QueryExecuted::class, fn (QueryExecuted $e) => $this->section(PerformanceSection::class, function (PerformanceSection $p) use ($e) {
                $p->dbQueryCount++;
                $p->dbTimeMs = round($p->dbTimeMs + $e->time, 2);
            }));
        }

        if ($on('cache')) {
            $events->listen(CacheHit::class, fn () => $this->section(PerformanceSection::class, fn (PerformanceSection $p) => $p->cacheHits++));
            $events->listen(CacheMissed::class, fn () => $this->section(PerformanceSection::class, fn (PerformanceSection $p) => $p->cacheMisses++));
        }

        if ($on('http_client')) {
            $events->listen(ResponseReceived::class, fn (ResponseReceived $e) => $this->section(PerformanceSection::class, function (PerformanceSection $p) use ($e) {
                $p->httpClientCount++;
                $p->httpClientTimeMs += round(($e->response->transferStats?->getTransferTime() ?? 0) * 1000, 1);
            }));
            $events->listen(ConnectionFailed::class, fn () => $this->section(PerformanceSection::class, fn (PerformanceSection $p) => $p->httpClientCount++));
        }

        if ($on('queue')) {
            $events->listen(JobQueued::class, fn () => $this->section(PerformanceSection::class, fn (PerformanceSection $p) => $p->queueDispatched++));
        }
    }

    protected function startJob(JobProcessing $e): void
    {
        if (! config('jot.enabled', true)) {
            return;
        }

        // Laravel hydrated Context from the payload before this listener ran.
        $this->push($e->job)->start(RequestSection::fromContext('queue'), new JobSection($e->job));
    }

    protected function endJob(object $job, string $status): void
    {
        $this->pop($job, fn (Jot $jot) => $jot->builtIn(JobSection::class)->status = $status);
    }

    protected function startCommand(CommandStarting $e): void
    {
        if (! config('jot.enabled', true) || Str::is(config('jot.console.ignore', []), $e->command)) {
            return;
        }

        $this->push($e->input)->start(
            RequestSection::fromContext('console'),
            new ConsoleSection($e->command),
        );
    }

    protected function endCommand(CommandFinished $e): void
    {
        $fill = function (Jot $jot) use ($e) {
            $console = $jot->builtIn(ConsoleSection::class);
            $console->exitCode = $e->exitCode;
            [$console->arguments, $console->options] = $this->passed($e->command, $e->input);
        };

        // Laravel reports a command's exception after CommandFinished: a failed outer command emits on terminate, with its error.
        if ($e->exitCode !== 0 && count($this->stack) === 1 && $this->top() === $e->input) {
            $fill(app(Jot::class));

            return;
        }

        $this->pop($e->input, $fill);
    }

    /**
     * The names of the arguments and options that differ from their default.
     * Read at the end: the input is bound to the command's definition by then.
     *
     * @return array{list<string>, list<string>}
     */
    protected function passed(string $command, InputInterface $input): array
    {
        $definition = (app(Kernel::class)->all()[$command] ?? null)?->getDefinition();

        if (! $definition) {
            return [[], []];
        }

        $arguments = array_filter(
            $definition->getArguments(),
            fn ($argument) => $argument->getName() !== 'command'
                && $input->hasArgument($argument->getName())
                && $input->getArgument($argument->getName()) !== $argument->getDefault(),
        );

        $options = array_filter(
            $definition->getOptions(),
            fn ($option) => $input->hasOption($option->getName())
                && $input->getOption($option->getName()) !== $option->getDefault(),
        );

        return [
            array_values(array_map(fn ($argument) => $argument->getName(), $arguments)),
            array_values(array_map(fn ($option) => '--'.$option->getName(), $options)),
        ];
    }

    protected function startTask(ScheduledTaskStarting $e): void
    {
        if (! config('jot.enabled', true)) {
            return;
        }

        $this->push($e->task)->start(
            new RequestSection('schedule'),
            new ScheduleSection($e->task),
        );
    }

    protected function endTask(object $task): void
    {
        $this->pop($task, fn (Jot $jot) => $jot->builtIn(ScheduleSection::class)->exitCode = $task->exitCode);
    }

    protected function push(object $unit): Jot
    {
        $this->stack[] = [$unit, app(Jot::class), Context::all(), Context::allHidden()];

        app()->instance(Jot::class, $jot = new Jot);

        return $jot;
    }

    /**
     * Emits the event of the unit on top of the stack, then restores what it replaced.
     * Any other unit is ignored: a job can fire JobFailed then JobProcessed.
     *
     * @param  Closure(Jot): mixed  $fill
     */
    protected function pop(object $unit, Closure $fill): void
    {
        if ($this->top() !== $unit) {
            return;
        }

        [, $previous, $context, $hidden] = array_pop($this->stack);

        $jot = app(Jot::class);
        $fill($jot);
        $jot->emit();

        app()->instance(Jot::class, $previous);
        Context::flush()->add($context)->addHidden($hidden);
        $previous->tagSentry();
    }

    protected function top(): ?object
    {
        return $this->stack ? end($this->stack)[0] : null;
    }

    /**
     * @template T of Section
     *
     * @param  class-string<T>  $class
     * @param  Closure(T): mixed  $change
     */
    protected function section(string $class, Closure $change): void
    {
        if ($section = jot()->builtIn($class)) {
            $change($section);
        }
    }
}
