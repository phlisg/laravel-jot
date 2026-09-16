<?php

namespace Phlisg\Jot;

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
}
