<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Category;
use App\Models\Product\InventoryAdjustment;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->admin = User::factory()->admin()->create();

        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics-'.Str::random(5),
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Wireless Headphones',
            'slug' => 'wireless-headphones-'.Str::random(5),
            'price' => 15000.00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'WH-BLK',
            'attributes' => ['color' => 'Black'],
            'price' => 15000.00,
            'stock' => 5,
            'status' => 'active',
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/inventory')
            ->assertUnauthorized();

        $this->postJson("/api/v1/admin/inventory/{$this->product->id}/adjust", [
            'quantity_delta' => 10,
            'reason' => 'restock',
        ])->assertUnauthorized();
    }

    public function test_unauthorized_user_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/inventory')
            ->assertForbidden();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/admin/inventory/{$this->product->id}/adjust", [
                'quantity_delta' => 10,
                'reason' => 'restock',
            ])->assertForbidden();
    }

    public function test_admin_can_adjust_product_stock_and_creates_ledger_and_audit(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/inventory/{$this->product->id}/adjust", [
                'quantity_delta' => 15,
                'reason' => 'restock',
                'notes' => 'Received shipment PO-1002',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity_delta', 15)
            ->assertJsonPath('data.quantity_before', 10)
            ->assertJsonPath('data.quantity_after', 25)
            ->assertJsonPath('data.reason', 'restock')
            ->assertJsonPath('data.notes', 'Received shipment PO-1002');

        $this->product->refresh();
        $this->assertEquals(25, $this->product->stock);

        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id' => $this->product->id,
            'variant_id' => null,
            'adjusted_by' => $this->admin->id,
            'quantity_delta' => 15,
            'quantity_before' => 10,
            'quantity_after' => 25,
            'reason' => 'restock',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'inventory.adjusted',
            'actor_id' => $this->admin->id,
            'reason' => 'restock',
        ]);
    }

    public function test_admin_can_adjust_variant_stock(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/inventory/{$this->product->id}/adjust", [
                'variant_id' => $this->variant->id,
                'quantity_delta' => 20,
                'reason' => 'supplier_receipt',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.variant_id', $this->variant->id)
            ->assertJsonPath('data.quantity_before', 5)
            ->assertJsonPath('data.quantity_after', 25);

        $this->variant->refresh();
        $this->assertEquals(25, $this->variant->stock);
    }

    public function test_reducing_stock_below_zero_fails_validation(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/inventory/{$this->product->id}/adjust", [
                'quantity_delta' => -20, // Stock is only 10
                'reason' => 'damage',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_delta');

        $this->product->refresh();
        $this->assertEquals(10, $this->product->stock);
        $this->assertDatabaseCount('inventory_adjustments', 0);
    }

    public function test_delta_of_zero_is_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/inventory/{$this->product->id}/adjust", [
                'quantity_delta' => 0,
                'reason' => 'manual_adjustment',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_delta');
    }

    public function test_admin_can_list_inventory_adjustments_with_normalized_pagination(): void
    {
        InventoryAdjustment::create([
            'product_id' => $this->product->id,
            'variant_id' => null,
            'adjusted_by' => $this->admin->id,
            'quantity_delta' => 5,
            'quantity_before' => 10,
            'quantity_after' => 15,
            'reason' => 'restock',
            'notes' => 'Batch restock',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/admin/inventory?product_id={$this->product->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id',
                            'product_id',
                            'quantity_delta',
                            'quantity_before',
                            'quantity_after',
                            'reason',
                            'created_at',
                        ],
                    ],
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                        'from',
                        'to',
                    ],
                ],
            ])
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.reason', 'restock');
    }
}
