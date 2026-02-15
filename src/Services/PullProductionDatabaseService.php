<?php

namespace Ruelluna\DbPullProduction\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class PullProductionDatabaseService
{
    private const BACKUP_WEIGHT = 33;

    private const DUMP_WEIGHT = 33;

    private const IMPORT_WEIGHT = 34;

    public function __construct(
        private int $timeout = 0
    ) {}

    public function execute(bool $skipBackup = false, ?callable $onProgress = null): bool
    {
        $config = config('db-pull-production');
        $ssh = $config['ssh'] ?? [];
        $remoteDb = $config['database'] ?? [];
        $localDb = config('database.connections.'.config('database.default'));

        $localDatabase = $localDb['database'];
        $localUsername = $localDb['username'];
        $localPassword = $localDb['password'] ?? '';
        $localHost = $localDb['host'] ?? '127.0.0.1';
        $localPort = $localDb['port'] ?? 3306;

        if (! $skipBackup) {
            Log::info('Creating local database backup');
            $backupPath = $this->createLocalBackup(
                $localDatabase,
                $localUsername,
                $localPassword,
                $localHost,
                $localPort,
                fn (string $msg, int $pct) => $onProgress !== null && $onProgress($msg, (int) round($pct * self::BACKUP_WEIGHT / 100))
            );
            if ($backupPath === null) {
                throw new RuntimeException('Failed to create local backup.');
            }
            Log::info("Local backup saved to {$backupPath}");
        } elseif ($onProgress !== null) {
            $onProgress('Skipping backup', 0);
        }

        $dumpPath = $this->dumpProductionToFile($ssh, $remoteDb, $onProgress);

        $this->importDumpToLocal($dumpPath, $localHost, $localPort, $localUsername, $localPassword, $localDatabase, $onProgress);

        if (file_exists($dumpPath)) {
            unlink($dumpPath);
        }

        if ($onProgress !== null) {
            $onProgress('Complete', 100);
        }
        Log::info('Production database pulled successfully.');

        return true;
    }

    public static function validateConfig(array $ssh, array $remoteDb, array $localDb): array
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
            return ['error' => 'Default database connection must be MySQL.'];
        }

        if ($missing !== []) {
            return ['error' => 'Missing required configuration: '.implode(', ', $missing)];
        }

        return [];
    }

    private function createLocalBackup(
        string $database,
        string $username,
        string $password,
        string $host,
        int $port,
        ?callable $onProgress = null
    ): ?string {
        $backupDir = storage_path('app/backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $backupPath = "{$backupDir}/{$database}_{$timestamp}.sql";

        $estimatedBytes = $this->getDatabaseSizeBytes($host, $port, $username, $password, $database);

        $mysqldump = sprintf(
            'mysqldump -h %s -P %d -u %s %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($database)
        );

        $base = $this->timeout > 0 ? Process::timeout($this->timeout) : Process::forever();
        $process = $base->env(['MYSQL_PWD' => $password])->start("{$mysqldump} > ".escapeshellarg($backupPath));

        while ($process->running()) {
            if ($onProgress !== null && file_exists($backupPath)) {
                $bytes = filesize($backupPath);
                $pct = $estimatedBytes > 0 ? min(100, (int) round(100 * $bytes / $estimatedBytes)) : 0;
                $onProgress(sprintf('Backing up local database (%s)', $this->formatBytes($bytes)), $pct);
            }
            usleep(200000);
        }

        $result = $process->wait();

        if (! $result->successful()) {
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
            Log::error('Failed to create local backup', ['error' => $result->errorOutput()]);

            return null;
        }

        return $backupPath;
    }

    private function dumpProductionToFile(array $ssh, array $remoteDb, ?callable $onProgress): string
    {
        if ($onProgress !== null) {
            $onProgress('Dumping production database', self::BACKUP_WEIGHT);
        }
        Log::info('Dumping production database');

        $dumpPath = tempnam(sys_get_temp_dir(), 'db_pull_').'.sql';
        $sshCommand = $this->buildSshCommand($ssh);
        $mysqldumpCommand = $this->buildMysqldumpCommand($remoteDb);
        $remoteCommand = "{$sshCommand} \"{$mysqldumpCommand}\" > ".escapeshellarg($dumpPath);

        $base = $this->timeout > 0 ? Process::timeout($this->timeout) : Process::forever();
        $process = $base->start($remoteCommand);

        $estimatedBytes = $this->getRemoteDatabaseSizeBytes($ssh, $remoteDb);

        while ($process->running()) {
            if ($onProgress !== null && file_exists($dumpPath)) {
                $bytes = filesize($dumpPath);
                $pct = $estimatedBytes > 0 ? min(100, (int) round(100 * $bytes / $estimatedBytes)) : 0;
                $overall = self::BACKUP_WEIGHT + (int) round($pct * self::DUMP_WEIGHT / 100);
                $onProgress(sprintf('Dumping production database (%s)', $this->formatBytes($bytes)), $overall);
            }
            usleep(200000);
        }

        $result = $process->wait();

        if (! $result->successful()) {
            if (file_exists($dumpPath)) {
                unlink($dumpPath);
            }
            Log::error('Failed to dump production database', ['error' => $result->errorOutput()]);
            throw new RuntimeException('Failed to dump production database. '.$result->errorOutput());
        }

        return $dumpPath;
    }

    private function importDumpToLocal(
        string $dumpPath,
        string $host,
        int $port,
        string $username,
        string $password,
        string $database,
        ?callable $onProgress
    ): void {
        if ($onProgress !== null) {
            $onProgress('Importing into local database', self::BACKUP_WEIGHT + self::DUMP_WEIGHT);
        }
        Log::info('Importing into local database');

        $mysqlImport = sprintf(
            'mysql -h %s -P %d -u %s %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($database)
        );

        $base = $this->timeout > 0 ? Process::timeout($this->timeout) : Process::forever();

        if ($this->isPvAvailable() && $onProgress !== null) {
            $this->importWithPv($dumpPath, $mysqlImport, $password, $base, $onProgress);
        } elseif ($onProgress !== null) {
            $this->importWithStreaming($dumpPath, $mysqlImport, $password, $onProgress);
        } else {
            $importProcess = $base
                ->input(file_get_contents($dumpPath))
                ->env(['MYSQL_PWD' => $password])
                ->run($mysqlImport);

            if (! $importProcess->successful()) {
                Log::error('Failed to import into local database', ['error' => $importProcess->errorOutput()]);
                throw new RuntimeException('Failed to import into local database. '.$importProcess->errorOutput());
            }
        }
    }

    private function getDatabaseSizeBytes(string $host, int $port, string $username, string $password, string $database): int
    {
        $safeDb = str_replace("'", "''", $database);
        $query = "SELECT COALESCE(SUM(data_length + index_length), 0) AS size FROM information_schema.tables WHERE table_schema = '{$safeDb}'";
        $mysql = sprintf(
            'mysql -h %s -P %d -u %s -N -e %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($query)
        );

        $base = $this->timeout > 0 ? Process::timeout(60) : Process::forever();
        $result = $base->env(['MYSQL_PWD' => $password])->run($mysql);

        if (! $result->successful()) {
            return 0;
        }

        $output = trim($result->output());

        return (int) $output;
    }

    private function getRemoteDatabaseSizeBytes(array $ssh, array $remoteDb): int
    {
        $host = $remoteDb['host'] ?? '127.0.0.1';
        $port = $remoteDb['port'] ?? 3306;
        $database = $remoteDb['database'];
        $username = $remoteDb['username'];
        $password = $remoteDb['password'];

        $safeDb = str_replace("'", "''", $database);
        $query = "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '{$safeDb}'";
        $mysqlCmd = sprintf(
            'mysql -h %s -P %d -u %s -p%s -N -e %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($query)
        );
        $sshCommand = $this->buildSshCommand($ssh);
        $remoteCommand = "{$sshCommand} ".escapeshellarg($mysqlCmd);

        $base = $this->timeout > 0 ? Process::timeout(60) : Process::forever();
        $result = $base->run($remoteCommand);

        if (! $result->successful()) {
            return 0;
        }

        return (int) trim($result->output());
    }

    private function importWithPv(
        string $dumpPath,
        string $mysqlImport,
        string $password,
        object $base,
        callable $onProgress
    ): void {
        $importCmd = sprintf(
            'pv %s | %s',
            escapeshellarg($dumpPath),
            $mysqlImport
        );
        $lastPercent = self::BACKUP_WEIGHT + self::DUMP_WEIGHT;

        $outputCallback = function (string $type, string $data) use ($onProgress, &$lastPercent): void {
            if ($type !== 'err') {
                return;
            }
            if (preg_match('/(\d+)\s*%/', $data, $m)) {
                $pct = (int) $m[1];
                $overall = self::BACKUP_WEIGHT + self::DUMP_WEIGHT + (int) round($pct * self::IMPORT_WEIGHT / 100);
                if ($overall > $lastPercent) {
                    $lastPercent = $overall;
                    $onProgress(sprintf('Importing into local database (%d%%)', $pct), $overall);
                }
            }
        };

        $process = $base
            ->env(['MYSQL_PWD' => $password])
            ->start($importCmd);

        $result = $process->wait($outputCallback);

        if (! $result->successful()) {
            Log::error('Failed to import into local database', ['error' => $result->errorOutput()]);
            throw new RuntimeException('Failed to import into local database. '.$result->errorOutput());
        }
    }

    private function importWithStreaming(
        string $dumpPath,
        string $mysqlImport,
        string $password,
        callable $onProgress
    ): void {
        $totalBytes = filesize($dumpPath);
        $lastPercent = self::BACKUP_WEIGHT + self::DUMP_WEIGHT;

        $env = getenv();
        $env = is_array($env) ? $env : [];
        $env['MYSQL_PWD'] = $password;

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($mysqlImport, $descriptorSpec, $pipes, null, $env);

        if (! is_resource($proc)) {
            throw new RuntimeException('Failed to start mysql process.');
        }

        $file = fopen($dumpPath, 'rb');
        $bytesRead = 0;
        $chunkSize = 64 * 1024;

        while (! feof($file)) {
            $chunk = fread($file, $chunkSize);
            if ($chunk !== false && $chunk !== '') {
                fwrite($pipes[0], $chunk);
                $bytesRead += strlen($chunk);
                if ($totalBytes > 0) {
                    $pct = min(100, (int) round(100 * $bytesRead / $totalBytes));
                    $overall = self::BACKUP_WEIGHT + self::DUMP_WEIGHT + (int) round($pct * self::IMPORT_WEIGHT / 100);
                    if ($overall > $lastPercent) {
                        $lastPercent = $overall;
                        $onProgress(sprintf('Importing into local database (%d%%)', $pct), $overall);
                    }
                }
            }
        }

        fclose($file);
        fclose($pipes[0]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            Log::error('Failed to import into local database', ['error' => $stderr]);
            throw new RuntimeException('Failed to import into local database. '.$stderr);
        }

        $onProgress('Importing into local database', 100);
    }

    private function isPvAvailable(): bool
    {
        $result = Process::run('pv -V 2>&1');

        return $result->successful();
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
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
