<?php

namespace MarvelousDigital\WideEvents\Tests;

use Livewire\LivewireServiceProvider;
use MarvelousDigital\WideEvents\JotServiceProvider;
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
