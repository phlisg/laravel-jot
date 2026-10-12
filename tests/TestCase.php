<?php

namespace MarvelousDigital\WideEvents\Tests;

use MarvelousDigital\WideEvents\JotServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            JotServiceProvider::class,
        ];
    }
}
