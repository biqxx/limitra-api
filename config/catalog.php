<?php

return [
    'cache_ttl' => (int) env('CATALOG_CACHE_TTL', 3600),
    'categories_cache_ttl' => (int) env('CATALOG_CATEGORIES_CACHE_TTL', 3600),
];
