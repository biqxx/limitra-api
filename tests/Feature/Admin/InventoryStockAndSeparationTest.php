<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockAndSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->admin = User::factory()->admin()->create();

        $this->category = Category::create([
            'name' => 'Computers & Accessories',
            'slug' => 'computers-accessories',
            'is_active' => true,
        ]);
    }

    public function test_inventory_overview_shows_stock_status_and_thresholds(): void
    {
        // In stock
        $pInStock = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Mechanical Keyboard',
            'slug' => 'mechanical-keyboard',
            'price' => 25000,
            'stock' => 50,
            'low_stock_threshold' => 10,
            'status' => 'active',
        ]);

        // Low stock
        $pLowStock = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Gaming Mouse',
            'slug' => 'gaming-mouse',
            'price' => 12000,
            'stock' => 5,
            'low_stock_threshold' => 10,
            'status' => 'active',
        ]);

        // Out of stock
        $pOutOfStock = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Ultrawide Monitor',
            'slug' => 'ultrawide-monitor',
            'price' => 180000,
            'stock' => 0,
            'low_stock_threshold' => 5,
            'status' => 'active',
        ]);

        // Variant on low stock product
        ProductVariant::create([
            'product_id' => $pLowStock->id,
            'sku' => 'GM-RGB',
            'attributes' => ['edition' => 'RGB'],
            'price' => 12000,
            'stock' => 3,
            'low_stock_threshold' => 5,
            'status' => 'active',
        ]);

        // Default inventory overview endpoint
        $res = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/inventory');

        $res->assertOk()
            ->assertJsonPath('data.pagination.total', 3);

        // Filter low stock
        $lowStockRes = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/inventory?status=low_stock');

        $lowStockRes->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Gaming Mouse')
            ->assertJsonPath('data.items.0.stock_status', 'low_stock')
            ->assertJsonPath('data.items.0.variants.0.stock_status', 'low_stock');

        // Filter out of stock
        $outOfStockRes = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/inventory?status=out_of_stock');

        $outOfStockRes->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Ultrawide Monitor')
            ->assertJsonPath('data.items.0.stock_status', 'out_of_stock');
    }

    public function test_inventory_adjustments_endpoint_separation(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'USB-C Hub',
            'slug' => 'usb-c-hub',
            'price' => 8000,
            'stock' => 20,
            'low_stock_threshold' => 5,
            'status' => 'active',
        ]);

        // Post adjustment to dedicated ledger endpoint /admin/inventory/adjustments
        $postRes = $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/admin/inventory/adjustments', [
                'product_id' => $product->id,
                'quantity_delta' => -16, // drops stock to 4, below threshold of 5!
                'reason' => 'damage',
                'notes' => 'Damaged during water leak in warehouse aisle 3',
            ]);

        $postRes->assertCreated()
            ->assertJsonPath('data.quantity_delta', -16)
            ->assertJsonPath('data.quantity_before', 20)
            ->assertJsonPath('data.quantity_after', 4);

        $this->assertEquals(4, $product->fresh()->stock);

        // Verify low stock audit event was automatically logged
        $this->assertDatabaseHas('audit_events', [
            'action' => 'inventory.low_stock',
            'reason' => 'stock_threshold_breached',
        ]);

        // Dedicated ledger GET endpoint /admin/inventory/adjustments
        $getAdjustmentsRes = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/inventory/adjustments');

        $getAdjustmentsRes->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.reason', 'damage');
    }

    public function test_threshold_updates_and_stable_variant_validation(): void
    {
        $productA = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Product A',
            'slug' => 'product-a',
            'price' => 5000,
            'stock' => 15,
            'low_stock_threshold' => 10,
            'status' => 'active',
        ]);

        $productB = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Product B',
            'slug' => 'product-b',
            'price' => 7000,
            'stock' => 20,
            'low_stock_threshold' => 5,
            'status' => 'active',
        ]);

        $variantB = ProductVariant::create([
            'product_id' => $productB->id,
            'sku' => 'PB-VAR',
            'attributes' => ['color' => 'Blue'],
            'price' => 7000,
            'stock' => 10,
            'low_stock_threshold' => 3,
            'status' => 'active',
        ]);

        // Update product A threshold
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/inventory/{$productA->id}/threshold", [
                'low_stock_threshold' => 25,
            ])
            ->assertOk()
            ->assertJsonPath('data.low_stock_threshold', 25);

        $this->assertEquals(25, $productA->fresh()->low_stock_threshold);

        // Update variant B threshold
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/inventory/{$productB->id}/threshold", [
                'variant_id' => $variantB->id,
                'low_stock_threshold' => 8,
            ])
            ->assertOk()
            ->assertJsonPath('data.low_stock_threshold', 8);

        $this->assertEquals(8, $variantB->fresh()->low_stock_threshold);

        // Attempting to adjust Product A with Variant B (which belongs to Product B) is rejected
        $rejectRes = $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/inventory/{$productA->id}/adjust", [
                'variant_id' => $variantB->id,
                'quantity_delta' => 5,
                'reason' => 'restock',
            ]);

        $rejectRes->assertUnprocessable()
            ->assertJsonValidationErrors('variant_id');
    }
}
