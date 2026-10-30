<?php

namespace Tests\Feature\Product;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDiscoveryAndImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_product_suggestions_returns_categories_products_and_terms(): void
    {
        $category = Category::create(['name' => 'Audio & Speakers', 'slug' => 'audio-speakers']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Audio Bluetooth Speaker Pro',
            'slug' => 'audio-bluetooth-speaker-pro',
            'sku' => 'AUD-SPK-01',
            'price' => 150.00,
            'stock' => 10,
            'status' => 'active',
            'brand' => 'AudioMax',
        ]);

        $response = $this->getJson('/api/v1/products/suggestions?q=Audio');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.query', 'Audio')
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.categories.0.name', 'Audio & Speakers')
            ->assertJsonPath('data.products.0.name', 'Audio Bluetooth Speaker Pro');
    }

    public function test_product_related_and_alternatives(): void
    {
        $category = Category::create(['name' => 'Laptops', 'slug' => 'laptops']);

        $main = Product::create([
            'category_id' => $category->id,
            'name' => 'Pro Laptop 15',
            'slug' => 'pro-laptop-15',
            'sku' => 'LAP-001',
            'price' => 1000.00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $related = Product::create([
            'category_id' => $category->id,
            'name' => 'Budget Laptop 14',
            'slug' => 'budget-laptop-14',
            'sku' => 'LAP-002',
            'price' => 950.00, // within 30% tolerance: alternative!
            'stock' => 8,
            'status' => 'active',
        ]);

        $expensive = Product::create([
            'category_id' => $category->id,
            'name' => 'Ultra Gaming Laptop',
            'slug' => 'ultra-gaming-laptop',
            'sku' => 'LAP-003',
            'price' => 3000.00, // outside 30% tolerance
            'stock' => 2,
            'status' => 'active',
        ]);

        // Related returns products in same category
        $this->getJson("/api/v1/products/{$main->id}/related")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.items');

        // Alternatives filters by price tolerance (+-30% of 1000 => 700 - 1300)
        $altRes = $this->getJson("/api/v1/products/{$main->id}/alternatives")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $related->id);
    }

    public function test_product_bulk_import_with_validation_and_dry_run(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Gadgets', 'slug' => 'gadgets']);

        $payload = [
            'dry_run' => true,
            'rows' => [
                [
                    'name' => 'Smart Fitness Band',
                    'price' => '49.99',
                    'stock' => 100,
                    'sku' => 'SFT-BND-01',
                    'category_id' => $category->id,
                    'brand' => 'FitPro',
                ],
                [
                    'name' => '', // Invalid row
                    'price' => 'invalid-price',
                ],
            ],
        ];

        // Dry run
        $dryRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/products/imports', $payload);

        $dryRes->assertCreated()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.successful_rows', 1)
            ->assertJsonPath('data.failed_rows', 1);

        $this->assertDatabaseMissing('products', ['sku' => 'SFT-BND-01']);

        // Real import
        $payload['dry_run'] = false;
        $realRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/products/imports', $payload);

        $realRes->assertCreated()
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.successful_rows', 1)
            ->assertJsonPath('data.failed_rows', 1);

        $this->assertDatabaseHas('products', [
            'sku' => 'SFT-BND-01',
            'name' => 'Smart Fitness Band',
            'price' => 49.99,
        ]);
    }

    public function test_product_import_template(): void
    {
        $this->getJson('/api/v1/products/import-template')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['columns', 'sample_row']]);
    }
}
