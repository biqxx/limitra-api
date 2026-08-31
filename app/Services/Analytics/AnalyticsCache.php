<?php

namespace App\Services\Analytics;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AnalyticsCache
{
    private const VERSION_KEY = 'analytics:dashboard:version';

    public function remember(string $section, array $parameters, Closure $resolver): array
    {
        $key = implode(':', [
            'analytics',
            'dashboard',
            $this->version(),
            $section,
            hash('xxh128', json_encode($parameters, JSON_THROW_ON_ERROR)),
        ]);

        return Cache::remember(
            $key,
            (int) config('analytics.dashboard_cache_ttl', 300),
            $resolver,
        );
    }

    public function invalidate(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    public function version(): string
    {
        return (string) Cache::get(self::VERSION_KEY, 'initial');
    }
}
