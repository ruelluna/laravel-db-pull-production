<?php

namespace Ruelluna\DbPullProduction;

use Illuminate\Support\ServiceProvider;

class DbPullProductionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-pull-production.php', 'db-pull-production');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\PullProductionDatabaseCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/db-pull-production.php' => config_path('db-pull-production.php'),
            ], 'db-pull-production-config');
        }
    }
}
