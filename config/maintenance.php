<?php

return [
    'auth_session_retention_days' => (int) env('MAINTENANCE_AUTH_SESSION_RETENTION_DAYS', 30),
    'checkout_quote_retention_hours' => (int) env('MAINTENANCE_CHECKOUT_QUOTE_RETENTION_HOURS', 24),
    'analytics_retention_days' => (int) env('MAINTENANCE_ANALYTICS_RETENTION_DAYS', 90),
    'prune_batch_size' => (int) env('MAINTENANCE_PRUNE_BATCH_SIZE', 1000),
];
