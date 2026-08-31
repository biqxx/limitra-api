<?php

namespace App\Observers;

use App\Models\Image;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductSpecification;
use App\Models\Product\ProductVariant;
use App\Services\Catalog\CatalogCache;
use Illuminate\Database\Eloquent\Model;

class CatalogCacheObserver
{
    public function __construct(private readonly CatalogCache $cache) {}

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        if ($model instanceof Product || $model instanceof ProductVariant || $model instanceof ProductSpecification) {
            $this->cache->invalidateProducts();

            return;
        }

        if ($model instanceof Category) {
            $this->cache->invalidateCategories();

            return;
        }

        if (! $model instanceof Image) {
            return;
        }

        match ($model->imageable_type) {
            Product::class => $this->cache->invalidateProducts(),
            Category::class => $this->cache->invalidateCategories(),
            default => null,
        };
    }
}
