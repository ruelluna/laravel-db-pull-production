<?php

return [
    'timeout' => (int) env('DB_PULL_PRODUCTION_TIMEOUT', 600),
    'job_timeout' => (int) env('DB_PULL_PRODUCTION_JOB_TIMEOUT', 3600),
    'ssh' => [
        'host' => env('PRODUCTION_SSH_HOST'),
        'user' => env('PRODUCTION_SSH_USER', 'forge'),
        'port' => (int) env('PRODUCTION_SSH_PORT', 22),
        'key_path' => env('PRODUCTION_SSH_KEY_PATH'),
    ],
    'database' => [
        'host' => env('PRODUCTION_DB_HOST', '127.0.0.1'),
        'port' => (int) env('PRODUCTION_DB_PORT', 3306),
        'database' => env('PRODUCTION_DB_DATABASE'),
        'username' => env('PRODUCTION_DB_USERNAME', 'forge'),
        'password' => env('PRODUCTION_DB_PASSWORD'),
    ],
];
