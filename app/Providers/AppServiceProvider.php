<?php

namespace App\Providers;

use App\Listeners\InvalidateNotificationUnreadCount;
use App\Models\Image;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductSpecification;
use App\Models\Product\ProductVariant;
use App\Models\User;
use App\Observers\CatalogCacheObserver;
use App\Observers\UserRoleObserver;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
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
        Event::listen(NotificationSent::class, InvalidateNotificationUnreadCount::class);

        Product::observe(CatalogCacheObserver::class);
        ProductVariant::observe(CatalogCacheObserver::class);
        ProductSpecification::observe(CatalogCacheObserver::class);
        Category::observe(CatalogCacheObserver::class);
        Image::observe(CatalogCacheObserver::class);
        User::observe(UserRoleObserver::class);
    }
}
