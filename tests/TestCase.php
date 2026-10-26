<?php

namespace Phlisg\WideEvents\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Phlisg\WideEvents\JotServiceProvider;

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
