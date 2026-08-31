<?php

namespace App\Services\Catalog;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogCache
{
    private const PRODUCTS_VERSION_KEY = 'catalog:products:version';

    private const CATEGORIES_VERSION_KEY = 'catalog:categories:version';

    public function rememberProducts(string $section, array $parameters, Closure $resolver): array
    {
        return $this->remember('products', $section, $parameters, $resolver);
    }

    public function rememberCategories(string $section, array $parameters, Closure $resolver): array
    {
        return $this->remember('categories', $section, $parameters, $resolver);
    }

    public function invalidateProducts(): void
    {
        $this->invalidate(self::PRODUCTS_VERSION_KEY);
    }

    public function invalidateCategories(): void
    {
        $this->invalidate(self::CATEGORIES_VERSION_KEY);
    }

    public function productsVersion(): string
    {
        return $this->version(self::PRODUCTS_VERSION_KEY);
    }

    public function categoriesVersion(): string
    {
        return $this->version(self::CATEGORIES_VERSION_KEY);
    }

    private function remember(string $domain, string $section, array $parameters, Closure $resolver): array
    {
        $key = implode(':', [
            'catalog',
            $domain,
            $this->productsVersion(),
            $this->categoriesVersion(),
            $section,
            hash('xxh128', json_encode($this->normalize($parameters), JSON_THROW_ON_ERROR)),
        ]);

        return Cache::remember(
            $key,
            (int) config('catalog.cache_ttl', 300),
            fn (): array => json_decode(
                json_encode($resolver(), JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function invalidate(string $key): void
    {
        $this->rotate($key);

        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->rotate($key));
        }
    }

    private function rotate(string $key): void
    {
        Cache::forever($key, (string) Str::uuid());
    }

    private function version(string $key): string
    {
        return (string) Cache::get($key, 'initial');
    }

    private function normalize(array $parameters): array
    {
        if (! array_is_list($parameters)) {
            ksort($parameters);
        }

        return array_map(
            fn (mixed $value): mixed => is_array($value) ? $this->normalize($value) : $value,
            $parameters,
        );
    }
}
