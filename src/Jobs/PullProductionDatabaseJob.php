<?php

namespace Ruelluna\DbPullProduction\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Ruelluna\DbPullProduction\Services\PullProductionDatabaseService;
use RuntimeException;

class PullProductionDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout;

    public function __construct(
        private bool $force = false,
        private bool $skipBackup = false
    ) {
        $this->timeout = config('db-pull-production.job_timeout', 3600);
    }

    public function handle(): void
    {
        if (! $this->force && config('app.env') === 'production') {
            Log::error('PullProductionDatabaseJob: Refusing to run in production. Use force option to override.');

            return;
        }

        $config = config('db-pull-production');
        $ssh = $config['ssh'] ?? [];
        $remoteDb = $config['database'] ?? [];
        $localDb = config('database.connections.'.config('database.default'));

        $validation = PullProductionDatabaseService::validateConfig($ssh, $remoteDb, $localDb);
        if ($validation !== []) {
            Log::error('PullProductionDatabaseJob: Configuration invalid', $validation);

            return;
        }

        try {
            $service = new PullProductionDatabaseService(0);
            $service->execute($this->skipBackup);
            Log::info('PullProductionDatabaseJob: Production database pulled successfully.');
        } catch (RuntimeException $e) {
            Log::error('PullProductionDatabaseJob: Failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
