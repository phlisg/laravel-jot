<?php

namespace Phlisg\WideEvents\Tests;

use Livewire\LivewireServiceProvider;
use Phlisg\WideEvents\JotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LivewireServiceProvider::class,
            JotServiceProvider::class,
        ];
    }
}
