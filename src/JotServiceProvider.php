<?php

namespace MarvelousDigital\WideEvents;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Blade;
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
            ->hasCommands(Commands\SectionCommand::class, Commands\EnvCommand::class, Commands\QueryCommand::class)
            ->hasInstallCommand(fn (InstallCommand $command) => $command
                ->publishConfigFile()
                ->endWith(fn (InstallCommand $command) => $command->call('jot:env')));
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

        Blade::directive('traceId', fn () => '<?php echo e(jot()->traceId()); ?>');

        // Laravel looks for error views in each view path, then its own: an app's 500 page still wins.
        if (config('jot.error_page', true)) {
            config(['view.paths' => [...config('view.paths', []), __DIR__.'/../resources/views']]);
        }

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
