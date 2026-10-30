<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateCommission;
use App\Models\Affiliate\AffiliateSale;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Affiliate $affiliate;

    private Order $order;

    private OrderItem $orderItem;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->admin = User::factory()->admin()->create();
        $affiliateUser = User::factory()->create();

        $this->affiliate = Affiliate::create([
            'user_id' => $affiliateUser->id,
            'slug' => 'test-affiliate',
            'code' => 'TESTAFF01',
            'commission_rate' => 10.00,
            'status' => 'active',
            'total_earnings' => 100.00,
            'total_paid' => 0.00,
        ]);

        $category = Category::create([
            'name' => 'General',
            'slug' => 'general-'.Str::random(5),
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Sample Product',
            'slug' => 'sample-product-'.Str::random(5),
            'price' => 1000.00,
            'stock' => 50,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create();

        $this->order = Order::create([
            'user_id' => $buyer->id,
            'number' => 'LMT-ORD-'.Str::random(8),
            'currency' => 'NGN',
            'subtotal' => 1000.00,
            'grand_total' => 1000.00,
            'total_amount' => 1000.00,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => 'card',
            'contact_email' => 'buyer@example.test',
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Lagos'],
        ]);

        $this->orderItem = OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => 'SKU-001',
            'quantity' => 1,
            'unit_price' => 1000.00,
            'price_at_purchase' => 1000.00,
            'line_total' => 1000.00,
        ]);
    }

    private function createCommission(string $status = 'pending', float $amount = 100.00): AffiliateCommission
    {
        $sale = AffiliateSale::create([
            'affiliate_id' => $this->affiliate->id,
            'order_id' => $this->order->id,
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->orderItem->product_id,
            'buyer_user_id' => $this->order->user_id,
            'sale_type' => 'direct',
            'sale_amount' => 1000.00,
        ]);

        return AffiliateCommission::create([
            'affiliate_id' => $this->affiliate->id,
            'affiliate_sale_id' => $sale->id,
            'commission_percent' => 10.00,
            'commission_amount' => $amount,
            'payment_status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    public function test_state_machine_happy_path_and_financial_balance(): void
    {
        $commission = $this->createCommission('pending', 100.00);

        // 1. Pending -> Approved
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/commissions/{$commission->id}", [
                'payment_status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'approved');

        $this->affiliate->refresh();
        $this->assertEquals(100.00, (float) $this->affiliate->total_earnings);
        $this->assertEquals(0.00, (float) $this->affiliate->total_paid);

        // 2. Approved -> Paid
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/commissions/{$commission->id}", [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->affiliate->refresh();
        $this->assertEquals(100.00, (float) $this->affiliate->total_earnings);
        $this->assertEquals(100.00, (float) $this->affiliate->total_paid);

        // 3. Paid -> Rejected (clawback): decrements both total_paid and total_earnings
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/commissions/{$commission->id}", [
                'payment_status' => 'rejected',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'rejected');

        $this->affiliate->refresh();
        $this->assertEquals(0.00, (float) $this->affiliate->total_earnings);
        $this->assertEquals(0.00, (float) $this->affiliate->total_paid);

        // 4. Rejected -> Approved: re-increments total_earnings
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/commissions/{$commission->id}", [
                'payment_status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'approved');

        $this->affiliate->refresh();
        $this->assertEquals(100.00, (float) $this->affiliate->total_earnings);
        $this->assertEquals(0.00, (float) $this->affiliate->total_paid);

        // Check audit event
        $this->assertDatabaseHas('audit_events', [
            'action' => 'commission.status_updated',
            'subject_id' => $commission->id,
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_invalid_state_transitions_are_rejected(): void
    {
        $commission = $this->createCommission('rejected', 50.00);

        // Rejected cannot directly become paid
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/commissions/{$commission->id}", [
                'payment_status' => 'paid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_status');

        $commission->update(['payment_status' => 'paid']);

        // Paid cannot transition to pending
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/v1/admin/commissions/{$commission->id}", [
                'payment_status' => 'pending',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_status');
    }

    public function test_bulk_update_respects_state_machine_and_locks(): void
    {
        $c1 = $this->createCommission('pending', 40.00);
        $c2 = $this->createCommission('pending', 60.00);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/admin/commissions/bulk-update', [
                'ids' => [$c1->id, $c2->id],
                'payment_status' => 'approved',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.updated', 2);

        $this->assertEquals('approved', $c1->fresh()->payment_status);
        $this->assertEquals('approved', $c2->fresh()->payment_status);
    }
}
