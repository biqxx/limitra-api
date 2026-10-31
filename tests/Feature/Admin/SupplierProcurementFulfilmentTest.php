<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Procurement\SupplierOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupplierProcurementFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        config(['services.supplier.base_url' => 'https://supplier.limitra.test']);
        config(['services.supplier.secret' => 'test-supplier-secret']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_can_create_supplier_procurement_order(): void
    {
        $customer = User::factory()->create();
        $order = Order::create([
            'user_id' => $customer->id,
            'number' => 'ORD-TEST-9001',
            'currency' => 'NGN',
            'subtotal' => 45000,
            'credit_total' => 0,
            'grand_total' => 45000,
            'total_amount' => 45000,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'fulfilment_status' => 'processing',
            'payment_method' => 'card',
            'contact_email' => $customer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '10 Victoria Island', 'country' => 'NG'],
        ]);

        $res = $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/admin/procurement/orders', [
                'order_id' => $order->id,
                'supplier' => '1688',
                'external_supplier_order_id' => '1688-ORD-882910',
                'procurement_cost_amount' => 200.00,
                'procurement_cost_currency' => 'CNY',
                'exchange_rate' => 215.5000,
                'carrier' => 'ZTO Express',
                'tracking_number' => 'ZTO88392100',
                'notes' => 'Bulk sourcing batch from Shenzhen factory',
            ]);

        $res->assertCreated()
            ->assertJsonPath('data.supplier', '1688')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.procurement_cost_amount', '200.00')
            ->assertJsonPath('data.local_cost_amount', '43100.00');

        $orderId = $res->json('data.id');
        $this->assertDatabaseHas('supplier_orders', [
            'id' => $orderId,
            'supplier' => '1688',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('supplier_order_events', [
            'supplier_order_id' => $orderId,
            'status' => 'pending',
            'description' => 'Supplier procurement order created.',
        ]);
    }

    public function test_procurement_status_timeline_updates_and_audit(): void
    {
        $supplierOrder = SupplierOrder::create([
            'supplier' => 'taobao',
            'external_supplier_order_id' => 'TB-9921',
            'status' => 'pending',
            'procurement_cost_amount' => 150.00,
            'procurement_cost_currency' => 'CNY',
            'exchange_rate' => 215.0000,
            'local_cost_amount' => 32250.00,
        ]);

        // Transition to purchased
        $purchasedRes = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/procurement/orders/{$supplierOrder->id}/status", [
                'status' => 'purchased',
                'description' => 'Payment settled with Taobao supplier, item packaged.',
                'location' => 'Guangzhou Supplier Warehouse',
            ]);

        $purchasedRes->assertOk()
            ->assertJsonPath('data.status', 'purchased');

        $this->assertNotNull($supplierOrder->fresh()->purchased_at);

        // Transition to received at hub
        $hubRes = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/procurement/orders/{$supplierOrder->id}/status", [
                'status' => 'received_at_warehouse',
                'description' => 'Arrived at Limitra Guangzhou Consolidation Hub.',
                'location' => 'Guangzhou Hub',
            ]);

        $hubRes->assertOk()
            ->assertJsonPath('data.status', 'received_at_warehouse');

        $this->assertNotNull($supplierOrder->fresh()->received_at_hub_at);

        // Verify status events in timeline
        $this->assertCount(2, $supplierOrder->fresh()->events);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'procurement.status_updated',
            'reason' => 'received_at_warehouse',
        ]);

        // View detail with events
        $showRes = $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/admin/procurement/orders/{$supplierOrder->id}");

        $showRes->assertOk()
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.0.status', 'received_at_warehouse');
    }

    public function test_procurement_sync_with_browser_service(): void
    {
        $supplierOrder = SupplierOrder::create([
            'supplier' => '1688',
            'external_supplier_order_id' => '1688-SYNC-1234',
            'status' => 'purchased',
            'procurement_cost_amount' => 300.00,
            'exchange_rate' => 215.0000,
            'local_cost_amount' => 64500.00,
        ]);

        Http::fake([
            'https://supplier.limitra.test/procurement/1688/orders/1688-SYNC-1234' => Http::response([
                'success' => true,
                'data' => [
                    'status' => 'dispatched',
                    'status_description' => 'Dispatched via SF Express to Shenzhen air freight terminal.',
                    'carrier' => 'SF Express',
                    'tracking_number' => 'SF999888111222',
                    'current_location' => 'Shenzhen Freight Center',
                ],
            ], 200),
        ]);

        $syncRes = $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/procurement/orders/{$supplierOrder->id}/sync");

        $syncRes->assertOk()
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('data.carrier', 'SF Express')
            ->assertJsonPath('data.tracking_number', 'SF999888111222');

        $this->assertSame('dispatched', $supplierOrder->fresh()->status);
        $this->assertDatabaseHas('supplier_order_events', [
            'supplier_order_id' => $supplierOrder->id,
            'status' => 'dispatched',
            'source' => 'browser_service',
        ]);
    }
}
