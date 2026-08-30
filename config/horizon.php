<?php

use App\Http\Middleware\HorizonAccess;
use Illuminate\Support\Str;

return [
    'name' => env('HORIZON_NAME', env('APP_NAME', 'Limitra')),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'limitra'), '_').'_horizon:'
    ),
    'middleware' => ['web', HorizonAccess::class],
    'auth' => [
        'username' => env('HORIZON_DASHBOARD_USER'),
        'password' => env('HORIZON_DASHBOARD_PASSWORD'),
    ],
    'waits' => [
        'redis:default' => (int) env('HORIZON_LONG_WAIT_SECONDS', 60),
    ],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'silenced_tags' => [],
    'metrics' => [
        'trim_snapshots' => [
            'job' => 288,
            'queue' => 288,
        ],
    ],
    'fast_termination' => true,
    'memory_limit' => (int) env('HORIZON_MEMORY_LIMIT', 128),
    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
    ],
    'environments' => [
        'production' => [
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESSES', 10),
            ],
        ],
        'local' => [
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESSES', 3),
            ],
        ],
        'testing' => [
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
        ],
        '*' => [
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESSES', 3),
            ],
        ],
    ],
];
