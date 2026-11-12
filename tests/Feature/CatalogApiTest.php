<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Image;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_public_catalog_filters_active_products_using_frontend_queries(): void
    {
        [$phones, $smartphones] = $this->categoryTree();

        $this->product($phones, $smartphones, [
            'name' => 'Apex 14 Pro',
            'slug' => 'apex-14-pro',
            'brand' => 'Apex',
            'price' => 485000,
            'compare_at_price' => 560000,
            'average_rating' => 4.8,
            'stock' => 8,
            'is_featured' => true,
        ]);
        $this->product($phones, $smartphones, [
            'name' => 'Budget Phone',
            'slug' => 'budget-phone',
            'brand' => 'Other',
            'price' => 120000,
            'average_rating' => 3.9,
            'stock' => 0,
        ]);
        $this->product($phones, $smartphones, [
            'name' => 'Draft Phone',
            'slug' => 'draft-phone',
            'brand' => 'Apex',
            'price' => 600000,
            'status' => 'draft',
        ]);

        $this->getJson('/api/v1/products?category_slug=phones&brand=Apex&min_rating=4&on_sale=1&in_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.slug', 'apex-14-pro')
            ->assertJsonPath('data.items.0.discount_percentage', 13)
            ->assertJsonPath('data.items.0.discount_active', true)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_product_discount_schedule_controls_sale_visibility_and_expiry_command(): void
    {
        [$phones, $smartphones] = $this->categoryTree();

        $activeDiscount = $this->product($phones, $smartphones, [
            'name' => 'Active Flash Sale Phone',
            'slug' => 'active-flash-sale-phone',
            'price' => 400000,
            'compare_at_price' => 500000,
            'discount_starts_at' => now()->subHour(),
            'discount_ends_at' => now()->addHours(2),
            'stock' => 5,
        ]);

        $expiredDiscount = $this->product($phones, $smartphones, [
            'name' => 'Expired Sale Phone',
            'slug' => 'expired-sale-phone',
            'price' => 300000,
            'compare_at_price' => 400000,
            'discount_starts_at' => now()->subDays(2),
            'discount_ends_at' => now()->subMinute(),
            'stock' => 5,
        ]);

        $futureDiscount = $this->product($phones, $smartphones, [
            'name' => 'Upcoming Sale Phone',
            'slug' => 'upcoming-sale-phone',
            'price' => 350000,
            'compare_at_price' => 450000,
            'discount_starts_at' => now()->addHour(),
            'discount_ends_at' => now()->addHours(5),
            'stock' => 5,
        ]);

        $saleResponse = $this->getJson('/api/v1/products?on_sale=1')->assertOk();
        $saleResponse->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $activeDiscount->id)
            ->assertJsonPath('data.items.0.discount_active', true)
            ->assertJsonPath('data.items.0.discount_percentage', 20);

        $this->assertGreaterThan(0, $saleResponse->json('data.items.0.discount_remaining_seconds'));

        $this->getJson("/api/v1/products/{$expiredDiscount->slug}")
            ->assertOk()
            ->assertJsonPath('data.discount_active', false)
            ->assertJsonPath('data.discount_percentage', 0)
            ->assertJsonPath('data.discount_remaining_seconds', null);

        $this->getJson("/api/v1/products/{$futureDiscount->slug}")
            ->assertOk()
            ->assertJsonPath('data.discount_active', false)
            ->assertJsonPath('data.discount_percentage', 0);

        $this->artisan('products:expire-discounts')->assertExitCode(0);

        $expiredDiscount->refresh();
        $this->assertSame('400000.00', $expiredDiscount->price);
        $this->assertNull($expiredDiscount->compare_at_price);
        $this->assertNull($expiredDiscount->discount_starts_at);
        $this->assertNull($expiredDiscount->discount_ends_at);
    }

    public function test_product_detail_supports_slug_and_returns_variants_specs_and_images(): void
    {
        [$phones, $smartphones] = $this->categoryTree();
        $product = $this->product($phones, $smartphones, [
            'name' => 'Apex 14 Pro',
            'slug' => 'apex-14-pro',
            'brand' => 'Apex',
            'price' => 485000,
        ]);

        $product->variants()->create([
            'sku' => 'APEX-BLK-256',
            'attributes' => ['color' => 'Black', 'storage' => '256GB'],
            'stock' => 3,
        ]);
        $product->specifications()->create([
            'group' => 'Display',
            'name' => 'Panel',
            'value' => '6.7 inch OLED',
            'sort_order' => 1,
        ]);
        Image::create([
            'imageable_id' => $product->id,
            'imageable_type' => Product::class,
            'type' => 'product_image',
            'path' => 'products/apex.webp',
            'alt' => 'Apex phone',
            'sort_order' => 1,
        ]);

        $this->getJson('/api/v1/products/apex-14-pro')
            ->assertOk()
            ->assertJsonPath('data.slug', 'apex-14-pro')
            ->assertJsonPath('data.variants.0.attributes.storage', '256GB')
            ->assertJsonPath('data.specifications.0.value', '6.7 inch OLED')
            ->assertJsonPath('data.images.0.url', 'products/apex.webp');
    }

    public function test_admin_can_create_complete_product_structure(): void
    {
        [$phones, $smartphones] = $this->categoryTree();
        $admin = User::create([
            'username' => 'catalog-admin',
            'email' => 'catalog-admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin, 'api')->postJson('/api/v1/products', [
            'category_id' => $phones->id,
            'subcategory_id' => $smartphones->id,
            'name' => 'Apex 14 Pro',
            'brand' => 'Apex',
            'sku' => 'APEX-14',
            'price' => 485000,
            'compare_at_price' => 560000,
            'stock' => 8,
            'status' => 'active',
            'variants' => [[
                'sku' => 'APEX-14-BLK',
                'attributes' => ['color' => 'Black'],
                'stock' => 8,
            ]],
            'specifications' => [[
                'group' => 'Display',
                'name' => 'Panel',
                'value' => 'OLED',
                'position' => 1,
            ]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'apex-14-pro')
            ->assertJsonPath('data.variants.0.sku', 'APEX-14-BLK');

        $this->assertDatabaseHas('product_specifications', ['name' => 'Panel', 'value' => 'OLED']);
    }

    private function categoryTree(): array
    {
        $parent = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        $child = Category::create(['parent_id' => $parent->id, 'name' => 'Smartphones', 'slug' => 'smartphones', 'active' => true]);

        return [$parent, $child];
    }

    private function product(Category $category, Category $subcategory, array $attributes): Product
    {
        return Product::create(array_merge([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'name' => 'Product',
            'slug' => fake()->unique()->slug(),
            'price' => 10000,
            'stock' => 1,
            'status' => 'active',
        ], $attributes));
    }
}
