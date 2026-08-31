<?php

return [
    'cache_ttl' => (int) env('AUTH_SESSION_CACHE_TTL', 300),
    'negative_cache_ttl' => (int) env('AUTH_SESSION_NEGATIVE_CACHE_TTL', 30),
    'touch_interval' => (int) env('AUTH_SESSION_TOUCH_INTERVAL', 300),
];
