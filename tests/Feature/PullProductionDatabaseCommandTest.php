<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('app.env', 'local');
    config()->set('db-pull-production.ssh', [
        'host' => 'prod.example.com',
        'user' => 'forge',
        'port' => 22,
        'key_path' => '/tmp/test_key',
    ]);
    config()->set('db-pull-production.database', [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'production_db',
        'username' => 'forge',
        'password' => 'secret',
    ]);
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'local_db',
        'username' => 'root',
        'password' => '',
    ]);
});

it('refuses to run when APP_ENV is production without force option', function () {
    config()->set('app.env', 'production');

    $exitCode = $this->artisan('db:pull-production');

    $exitCode->assertFailed();
});

it('runs when APP_ENV is production with force option', function () {
    config()->set('app.env', 'production');

    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $exitCode = $this->artisan('db:pull-production', ['--force' => true, '--no-backup' => true]);

    $exitCode->assertSuccessful();
});

it('fails when required config is missing', function () {
    config()->set('db-pull-production.ssh.host', null);

    $exitCode = $this->artisan('db:pull-production', ['--no-backup' => true]);

    $exitCode->assertFailed();
});

it('registers the db:pull-production command', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('db:pull-production');
});
