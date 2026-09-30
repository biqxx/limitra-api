<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSourcingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'services.supplier.base_url' => 'https://supplier.test',
            'services.supplier.secret' => 'internal-secret',
            'services.supplier.retries' => 1,
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_non_admin_cannot_use_sourcing_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/sourcing/sources')
            ->assertForbidden();
    }

    public function test_admin_can_search_suppliers_and_see_import_state(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Existing phone',
            'slug' => 'existing-phone',
            'price' => 100000,
            'stock' => 0,
            'status' => 'draft',
        ]);
        $product->sources()->create([
            'supplier' => 'amazon',
            'external_product_id' => 'B0EXISTING',
            'source_snapshot' => [],
        ]);
        Http::fake([
            'https://supplier.test/search*' => Http::response([
                'products' => [[
                    'id' => 'B0EXISTING',
                    'supplier_id' => 'B0EXISTING',
                    'supplier' => 'amazon',
                    'title' => 'Existing phone',
                ]],
                'total' => 1,
                'page' => 1,
                'supplier' => 'amazon',
            ]),
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/sourcing/search?source=amazon&q=phone')
            ->assertOk()
            ->assertJsonPath('data.products.0.imported', true)
            ->assertJsonPath('data.products.0.product_id', $product->id);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Internal-Service-Secret', 'internal-secret'));
    }

    public function test_admin_can_import_supplier_product_as_draft_and_cannot_duplicate_it(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        Http::fake([
            'https://supplier.test/import/amazon/B0TEST' => Http::response([
                'id' => 'B0TEST',
                'supplier_id' => 'B0TEST',
                'supplier' => 'amazon',
                'title' => 'Imported Phone',
                'description' => 'Supplier description',
                'price' => 249.99,
                'currency' => 'USD',
                'image_url' => 'https://images.test/phone.jpg',
                'product_url' => 'https://amazon.test/dp/B0TEST',
                'available' => true,
                'variants' => [['name' => 'Black']],
                'weight_lb' => 2.1,
                'us_delivery_days_min' => 2,
                'us_delivery_days_max' => 5,
                'restriction_status' => 'allowed',
                'validation_status' => 'valid',
                'final_price_ngn' => 625000,
                'cost_breakdown' => ['supplier_price_usd' => 249.99],
            ]),
        ]);

        $payload = [
            'source' => 'amazon',
            'external_product_id' => 'B0TEST',
            'category_id' => $category->id,
            'brand' => 'Example',
        ];

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/imports', $payload)
            ->assertCreated()
            ->assertJsonPath('data.supplier', 'amazon')
            ->assertJsonPath('data.product.name', 'Imported Phone')
            ->assertJsonPath('data.product.status', 'draft')
            ->assertJsonPath('data.product.stock', 0)
            ->assertJsonPath('data.product.price', '625000.00');

        $this->assertDatabaseHas('products', [
            'name' => 'Imported Phone',
            'currency' => 'NGN',
            'status' => 'draft',
            'stock' => 0,
        ]);
        $this->assertDatabaseHas('product_sources', [
            'supplier' => 'amazon',
            'external_product_id' => 'B0TEST',
            'validation_status' => 'valid',
        ]);
        $this->assertDatabaseHas('images', [
            'imageable_type' => Product::class,
            'path' => 'https://images.test/phone.jpg',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/imports', $payload)
            ->assertConflict();

        Http::assertSentCount(1);
    }

    public function test_import_requires_subcategory_to_belong_to_category(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        $other = Category::create(['name' => 'Clothes', 'slug' => 'clothes', 'active' => true]);
        $subcategory = Category::create([
            'parent_id' => $other->id,
            'name' => 'Dresses',
            'slug' => 'dresses',
            'active' => true,
        ]);

        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/sourcing/imports', [
            'source' => 'ebay',
            'external_product_id' => '123',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subcategory_id');

        Http::assertNothingSent();
    }
}
