<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // jot:demo sends requests in-process, so its own console event would swallow them.
        config()->push('jot.console.ignore', 'jot:demo');
        config()->push('jot.console.ignore', 'serve');
    }
}
