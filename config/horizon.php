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
        'redis:payments' => (int) env('HORIZON_PAYMENTS_LONG_WAIT_SECONDS', 15),
        'redis:notifications' => (int) env('HORIZON_NOTIFICATIONS_LONG_WAIT_SECONDS', 60),
        'redis:ai' => (int) env('HORIZON_AI_LONG_WAIT_SECONDS', 120),
        'redis:analytics' => (int) env('HORIZON_ANALYTICS_LONG_WAIT_SECONDS', 300),
        'redis:maintenance' => (int) env('HORIZON_MAINTENANCE_LONG_WAIT_SECONDS', 300),
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
        'supervisor-payments' => [
            'connection' => 'redis',
            'queue' => ['payments'],
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
        'supervisor-notifications' => [
            'connection' => 'redis',
            'queue' => ['notifications'],
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
        'supervisor-ai' => [
            'connection' => 'redis',
            'queue' => ['ai'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 180,
            'nice' => 0,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
        'supervisor-analytics' => [
            'connection' => 'redis',
            'queue' => ['analytics'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 3,
            'timeout' => 300,
            'nice' => 0,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
        'supervisor-maintenance' => [
            'connection' => 'redis',
            'queue' => ['maintenance'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 300,
            'nice' => 5,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
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
            'supervisor-payments' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_PAYMENTS_MAX_PROCESSES', 3),
            ],
            'supervisor-notifications' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_NOTIFICATIONS_MAX_PROCESSES', 2),
            ],
            'supervisor-ai' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_AI_MAX_PROCESSES', 2),
            ],
            'supervisor-analytics' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_ANALYTICS_MAX_PROCESSES', 2),
            ],
            'supervisor-maintenance' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_MAINTENANCE_MAX_PROCESSES', 1),
            ],
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', env('HORIZON_MAX_PROCESSES', 2)),
            ],
        ],
        'local' => [
            'supervisor-payments' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_PAYMENTS_MAX_PROCESSES', 1),
            ],
            'supervisor-notifications' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_NOTIFICATIONS_MAX_PROCESSES', 1),
            ],
            'supervisor-ai' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_AI_MAX_PROCESSES', 1),
            ],
            'supervisor-analytics' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_ANALYTICS_MAX_PROCESSES', 1),
            ],
            'supervisor-maintenance' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_MAINTENANCE_MAX_PROCESSES', 1),
            ],
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', env('HORIZON_MAX_PROCESSES', 1)),
            ],
        ],
        'testing' => [
            'supervisor-payments' => ['maxProcesses' => 1],
            'supervisor-notifications' => ['maxProcesses' => 1],
            'supervisor-ai' => ['maxProcesses' => 1],
            'supervisor-analytics' => ['maxProcesses' => 1],
            'supervisor-maintenance' => ['maxProcesses' => 1],
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
        ],
        '*' => [
            'supervisor-payments' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_PAYMENTS_MAX_PROCESSES', 1),
            ],
            'supervisor-notifications' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_NOTIFICATIONS_MAX_PROCESSES', 1),
            ],
            'supervisor-ai' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_AI_MAX_PROCESSES', 1),
            ],
            'supervisor-analytics' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_ANALYTICS_MAX_PROCESSES', 1),
            ],
            'supervisor-maintenance' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_MAINTENANCE_MAX_PROCESSES', 1),
            ],
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', env('HORIZON_MAX_PROCESSES', 1)),
            ],
        ],
    ],
];
