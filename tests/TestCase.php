<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ziming\LaravelMyinfoSg\LaravelMyinfoSgServiceProvider;

class TestCase extends Orchestra
{
    public function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelMyinfoSgServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
