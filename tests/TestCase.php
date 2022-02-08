<?php

namespace Ziming\LaravelMyinfoSg\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSgServiceProvider;

class TestCase extends Orchestra
{
    public function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelMyinfoSgServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}
