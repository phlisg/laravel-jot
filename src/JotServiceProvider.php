<?php

namespace Phlisg\Jot;

use Illuminate\Contracts\Http\Kernel;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class JotServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('jot')
            ->hasConfigFile()
            ->hasInstallCommand(fn (InstallCommand $command) => $command->publishConfigFile());

        $this->app->scoped(Jot::class);
    }

    public function packageRegistered(): void
    {
        // Register the middleware
        $this->app->singleton(Middleware\Jot::class);
    }

    public function packageBooted(): void
    {
        // Append the middleware to the kernel
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(Jot::class);
    }
}
