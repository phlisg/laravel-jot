<?php

namespace Phlisg\Jot\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Phlisg\Jot\JotServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            JotServiceProvider::class,
        ];
    }
}
