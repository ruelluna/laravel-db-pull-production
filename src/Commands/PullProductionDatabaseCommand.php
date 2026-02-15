<?php

namespace Ruelluna\DbPullProduction\Commands;

use Illuminate\Console\Command;
use Ruelluna\DbPullProduction\Jobs\PullProductionDatabaseJob;
use Ruelluna\DbPullProduction\Services\PullProductionDatabaseService;
use RuntimeException;

class PullProductionDatabaseCommand extends Command
{
    protected $signature = 'db:pull-production
                            {--force : Run even when APP_ENV=production}
                            {--no-backup : Skip backing up local database before replace}
                            {--async : Dispatch as queued job instead of running synchronously}
                            {--timeout= : Process timeout in seconds for sync mode (0 = unlimited)}';

    protected $description = 'Pull production MySQL database via SSH and replace local database';

    public function handle(): int
    {
        if (! $this->option('force') && config('app.env') === 'production') {
            $this->error('Refusing to run in production. Use --force to override.');

            return self::FAILURE;
        }

        $config = config('db-pull-production');
        $ssh = $config['ssh'] ?? [];
        $remoteDb = $config['database'] ?? [];
        $localDb = config('database.connections.'.config('database.default'));

        $validation = PullProductionDatabaseService::validateConfig($ssh, $remoteDb, $localDb);
        if ($validation !== []) {
            $this->error($validation['error'] ?? 'Configuration invalid.');
            if (str_contains($validation['error'] ?? '', 'Missing required')) {
                $this->line('Publish config: php artisan vendor:publish --tag=db-pull-production-config');
            }

            return self::FAILURE;
        }

        if ($this->option('async')) {
            PullProductionDatabaseJob::dispatch(
                $this->option('force'),
                $this->option('no-backup')
            );
            $this->info('Job dispatched. Monitor logs or queue worker for progress.');

            return self::SUCCESS;
        }

        $timeout = $this->option('timeout') !== null
            ? (int) $this->option('timeout')
            : config('db-pull-production.timeout', 600);

        $service = new PullProductionDatabaseService($timeout);

        $bar = $this->output->createProgressBar(100);
        $bar->setFormat(' %message% %current%%');
        $bar->start(0);

        $onProgress = fn (string $message, int $percent) => $bar->setMessage($message, 'message') && $bar->setProgress($percent);

        try {
            $service->execute($this->option('no-backup'), $onProgress);
        } catch (RuntimeException $e) {
            $bar->finish();
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $bar->setProgress(100);
        $bar->setMessage('Complete', 'message');
        $bar->finish();
        $this->newLine(2);
        $this->info('Production database pulled successfully.');

        return self::SUCCESS;
    }
}
