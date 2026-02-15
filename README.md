# Laravel DB Pull Production

Artisan command to pull a production MySQL database via SSH and replace your local database. Ideal for Laravel Forge deployments.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- MySQL as default database connection
- SSH access to production server
- `ssh`, `mysqldump`, and `mysql` in your system PATH

## Installation

```bash
composer require ruelluna/laravel-db-pull-production
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=db-pull-production-config
```

Add these variables to your `.env`:

```env
# Production DB Pull (db:pull-production)
PRODUCTION_SSH_HOST=
PRODUCTION_SSH_USER=forge
PRODUCTION_SSH_PORT=22
PRODUCTION_SSH_KEY_PATH=
PRODUCTION_DB_HOST=127.0.0.1
PRODUCTION_DB_PORT=3306
PRODUCTION_DB_DATABASE=
PRODUCTION_DB_USERNAME=forge
PRODUCTION_DB_PASSWORD=

# Optional: sync mode timeout in seconds (default 600, 0 = unlimited)
# DB_PULL_PRODUCTION_TIMEOUT=600

# Optional: job timeout in seconds when using --async (default 3600)
# DB_PULL_PRODUCTION_JOB_TIMEOUT=3600
```

**Note:** On Windows, use forward slashes for `PRODUCTION_SSH_KEY_PATH` (e.g. `C:/Users/Name/.ssh/id_rsa`) to avoid dotenv escape issues.

## Usage

```bash
php artisan db:pull-production
```

### Options

| Option | Description |
|--------|--------------|
| `--force` | Run even when `APP_ENV=production` (use with caution) |
| `--no-backup` | Skip backing up your local database before replace |
| `--async` | Dispatch as a queued job instead of running synchronously (avoids timeout for large databases) |
| `--timeout=` | Process timeout in seconds for sync mode (0 = unlimited). Overrides config. |

### Examples

```bash
# Pull production database (backs up local first)
php artisan db:pull-production

# Skip local backup
php artisan db:pull-production --no-backup

# Override production check (e.g. when running in staging)
php artisan db:pull-production --force

# Run as background job (requires queue worker: php artisan queue:work)
php artisan db:pull-production --async --no-backup

# Sync mode with custom timeout (e.g. 20 minutes)
php artisan db:pull-production --no-backup --timeout=1200
```

## Security

The command **refuses to run** when `APP_ENV=production` unless you pass `--force`. This prevents accidentally overwriting your production database with local data.

## How It Works

1. Validates that required config is present
2. Backs up your local database to `storage/app/backups/` (unless `--no-backup`)
3. SSHs into the production server
4. Runs `mysqldump` on the remote MySQL instance
5. Pipes the dump into your local `mysql` for import

**Large databases:** The sync command uses a configurable timeout (default 600 seconds). For very large databases, use `--async` to run as a queued job—ensure a queue worker is running (`php artisan queue:work`) and use a queue driver other than `sync` (e.g. `database` or `redis`) for true background execution.

**Progress display:** The command shows real percentage progress during backup, dump, and import. On Linux/macOS, `pv` (pipe viewer) is used for import progress when available (`brew install pv` / `apt install pv`). On Windows, a pure PHP streaming approach is used—no extra tools required.

## Testing

```bash
./vendor/bin/pest
```

## License

MIT
