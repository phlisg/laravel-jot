<?php

namespace Phlisg\Jot;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class JotServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('jot')
            ->hasConfigFile()
            ->hasCommand(Commands\SectionCommand::class)
            ->hasInstallCommand(fn (InstallCommand $command) => $command->publishConfigFile());
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(Jot::class);
        $this->app->singleton(Writer::class);
        $this->app->singleton(Lifecycle::class);
        $this->app->scoped(Middleware\JotRequest::class);
    }

    public function packageBooted(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(Middleware\JotRequest::class);
        $this->app->make(Lifecycle::class)->register($this->app['events']);

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            // Collision wraps the app handler in local console runs.
            if (property_exists($handler, 'appExceptionHandler')) {
                $handler = (fn () => $this->appExceptionHandler)->call($handler);
            }

            if ($handler instanceof Handler) {
                $handler->reportable(fn (Throwable $e) => jot()->error($e));
            }
        });
    }
}
