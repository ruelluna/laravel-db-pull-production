<?php

namespace Ruelluna\DbPullProduction\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PullProductionDatabaseCommand extends Command
{
    protected $signature = 'db:pull-production
                            {--force : Run even when APP_ENV=production}
                            {--no-backup : Skip backing up local database before replace}';

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

        if (! $this->validateConfig($ssh, $remoteDb, $localDb)) {
            return self::FAILURE;
        }

        $localDatabase = $localDb['database'];
        $localUsername = $localDb['username'];
        $localPassword = $localDb['password'] ?? '';
        $localHost = $localDb['host'] ?? '127.0.0.1';
        $localPort = $localDb['port'] ?? 3306;

        if (! $this->option('no-backup')) {
            $backupPath = $this->createLocalBackup($localDatabase, $localUsername, $localPassword, $localHost, $localPort);
            if ($backupPath === null) {
                return self::FAILURE;
            }
        }

        $sshCommand = $this->buildSshCommand($ssh);
        $mysqldumpCommand = $this->buildMysqldumpCommand($remoteDb);
        $remoteCommand = "{$sshCommand} \"{$mysqldumpCommand}\"";

        $this->info('Dumping production database...');

        $process = Process::run($remoteCommand);

        if (! $process->successful()) {
            $this->error('Failed to dump production database.');
            $this->error($process->errorOutput());

            return self::FAILURE;
        }

        $this->info('Importing into local database...');

        $mysqlImport = sprintf(
            'mysql -h %s -P %d -u %s %s',
            escapeshellarg($localHost),
            $localPort,
            escapeshellarg($localUsername),
            escapeshellarg($localDatabase)
        );

        $importProcess = Process::input($process->output())
            ->env(['MYSQL_PWD' => $localPassword])
            ->run($mysqlImport);

        if (! $importProcess->successful()) {
            $this->error('Failed to import into local database.');
            $this->error($importProcess->errorOutput());

            return self::FAILURE;
        }

        $this->info('Production database pulled successfully.');

        return self::SUCCESS;
    }

    private function validateConfig(array $ssh, array $remoteDb, array $localDb): bool
    {
        $missing = [];

        if (empty($ssh['host'])) {
            $missing[] = 'PRODUCTION_SSH_HOST';
        }
        if (empty($ssh['key_path'])) {
            $missing[] = 'PRODUCTION_SSH_KEY_PATH';
        }
        if (empty($remoteDb['database'])) {
            $missing[] = 'PRODUCTION_DB_DATABASE';
        }
        if (empty($remoteDb['username'])) {
            $missing[] = 'PRODUCTION_DB_USERNAME';
        }
        if (empty($remoteDb['password'])) {
            $missing[] = 'PRODUCTION_DB_PASSWORD';
        }
        if (empty($localDb['database'])) {
            $missing[] = 'DB_DATABASE (local)';
        }
        if (($localDb['driver'] ?? '') !== 'mysql') {
            $this->error('Default database connection must be MySQL.');

            return false;
        }

        if ($missing !== []) {
            $this->error('Missing required configuration: '.implode(', ', $missing));
            $this->line('Publish config: php artisan vendor:publish --tag=db-pull-production-config');

            return false;
        }

        return true;
    }

    private function createLocalBackup(
        string $database,
        string $username,
        string $password,
        string $host,
        int $port
    ): ?string {
        $backupDir = storage_path('app/backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $backupPath = "{$backupDir}/{$database}_{$timestamp}.sql";

        $this->info("Backing up local database to {$backupPath}...");

        $mysqldump = sprintf(
            'mysqldump -h %s -P %d -u %s %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($database)
        );

        $process = Process::env(['MYSQL_PWD' => $password])->run($mysqldump);

        if (! $process->successful()) {
            $this->error('Failed to create local backup.');
            $this->error($process->errorOutput());

            return null;
        }

        file_put_contents($backupPath, $process->output());

        return $backupPath;
    }

    private function buildSshCommand(array $ssh): string
    {
        $parts = [
            'ssh',
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'BatchMode=yes',
            '-p', (string) ($ssh['port'] ?? 22),
            '-i', escapeshellarg($ssh['key_path']),
            sprintf('%s@%s', $ssh['user'] ?? 'forge', $ssh['host']),
        ];

        return implode(' ', $parts);
    }

    private function buildMysqldumpCommand(array $db): string
    {
        $host = $db['host'] ?? '127.0.0.1';
        $port = $db['port'] ?? 3306;
        $database = $db['database'];
        $username = $db['username'];
        $password = $db['password'];

        return sprintf(
            'mysqldump -h %s -P %d -u %s -p%s --single-transaction --quick --lock-tables=false %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database)
        );
    }
}
