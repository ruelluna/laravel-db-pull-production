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

### Examples

```bash
# Pull production database (backs up local first)
php artisan db:pull-production

# Skip local backup
php artisan db:pull-production --no-backup

# Override production check (e.g. when running in staging)
php artisan db:pull-production --force
```

## Security

The command **refuses to run** when `APP_ENV=production` unless you pass `--force`. This prevents accidentally overwriting your production database with local data.

## How It Works

1. Validates that required config is present
2. Backs up your local database to `storage/app/backups/` (unless `--no-backup`)
3. SSHs into the production server
4. Runs `mysqldump` on the remote MySQL instance
5. Pipes the dump into your local `mysql` for import

## Local Development

To test the package locally before publishing to Packagist, add a path repository to your app's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-db-pull-production"
    }
]
```

Then:

```bash
composer require ruelluna/laravel-db-pull-production
```

## Testing

```bash
./vendor/bin/pest
```

## License

MIT
