<?php

namespace Ruelluna\DbPullProduction\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ruelluna\DbPullProduction\DbPullProductionServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DbPullProductionServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ]);
    }
}
