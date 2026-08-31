<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Catalog\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        Cache::flush();
    }

    public function test_repeated_catalog_requests_are_served_without_catalog_queries(): void
    {
        [$category, $product] = $this->catalog();

        $this->getJson('/api/v1/products?sort_by=price&sort_dir=asc&per_page=5')->assertOk();
        $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson("/api/v1/categories/{$category->id}")->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/api/v1/products?per_page=5&sort_dir=asc&sort_by=price')->assertOk();
        $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson("/api/v1/categories/{$category->id}")->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query) => strtolower($query));

        $this->assertFalse($queries->contains(fn (string $query) => str_contains($query, 'from "products"')));
        $this->assertFalse($queries->contains(fn (string $query) => str_contains($query, 'from "categories"')));
        $this->assertFalse($queries->contains(fn (string $query) => str_contains($query, 'from "product_variants"')));
    }

    public function test_product_price_and_stock_changes_invalidate_lists_details_and_category_counts(): void
    {
        [$category, $product] = $this->catalog();
        $cache = app(CatalogCache::class);

        $this->getJson('/api/v1/products')->assertJsonPath('data.items.0.price', '10000.00');
        $this->getJson("/api/v1/products/{$product->slug}")->assertJsonPath('data.stock', 5);
        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.products_count', 1);

        $productsVersion = $cache->productsVersion();
        $categoriesVersion = $cache->categoriesVersion();

        $product->update(['price' => 12500]);
        $product->increment('stock', 2);
        $this->product($category, ['slug' => 'second-product', 'sku' => 'CACHE-002', 'is_featured' => false]);

        $this->assertNotSame($productsVersion, $cache->productsVersion());
        $this->assertSame($categoriesVersion, $cache->categoriesVersion());
        $this->getJson('/api/v1/products')->assertJsonPath('data.items.0.price', '12500.00');
        $this->getJson("/api/v1/products/{$product->slug}")->assertJsonPath('data.stock', 7);
        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.products_count', 2);
    }

    public function test_category_and_category_image_changes_invalidate_embedded_product_data(): void
    {
        [$category, $product] = $this->catalog();
        $image = Image::create([
            'imageable_type' => Category::class,
            'imageable_id' => $category->id,
            'type' => 'category_image',
            'path' => 'https://cdn.test/old-category.jpg',
            'alt' => 'Old category image',
            'sort_order' => 0,
        ]);
        $cache = app(CatalogCache::class);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertJsonPath('data.category.name', 'Phones')
            ->assertJsonPath('data.category.image.url', 'https://cdn.test/old-category.jpg');

        $productsVersion = $cache->productsVersion();
        $categoriesVersion = $cache->categoriesVersion();

        $category->update(['name' => 'Smart Devices']);
        $image->update(['path' => 'https://cdn.test/new-category.jpg']);

        $this->assertSame($productsVersion, $cache->productsVersion());
        $this->assertNotSame($categoriesVersion, $cache->categoriesVersion());
        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertJsonPath('data.category.name', 'Smart Devices')
            ->assertJsonPath('data.category.image.url', 'https://cdn.test/new-category.jpg');
    }

    public function test_variant_changes_invalidate_product_details(): void
    {
        [, $product] = $this->catalog();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CACHE-VARIANT-1',
            'attributes' => ['storage' => '256GB'],
            'price' => 11000,
            'stock' => 3,
            'status' => 'active',
        ]);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertJsonPath('data.variants.0.stock', 3)
            ->assertJsonPath('data.variants.0.price', '11000.00');

        $variant->update(['price' => 11500, 'stock' => 1]);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertJsonPath('data.variants.0.stock', 1)
            ->assertJsonPath('data.variants.0.price', '11500.00');
    }

    /**
     * @return array{Category, Product}
     */
    private function catalog(): array
    {
        $category = Category::create([
            'name' => 'Phones',
            'slug' => 'phones',
            'active' => true,
        ]);

        return [$category, $this->product($category)];
    }

    private function product(Category $category, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Apex Phone',
            'slug' => 'apex-phone',
            'sku' => 'CACHE-001',
            'price' => 10000,
            'stock' => 5,
            'status' => 'active',
            'is_featured' => true,
        ], $overrides));
    }
}
