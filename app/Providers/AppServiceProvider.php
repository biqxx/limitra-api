<?php

namespace App\Providers;

use App\Models\Image;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductSpecification;
use App\Models\Product\ProductVariant;
use App\Observers\CatalogCacheObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Product::observe(CatalogCacheObserver::class);
        ProductVariant::observe(CatalogCacheObserver::class);
        ProductSpecification::observe(CatalogCacheObserver::class);
        Category::observe(CatalogCacheObserver::class);
        Image::observe(CatalogCacheObserver::class);
    }
}
