<?php

namespace Tests\Feature\Affiliate;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateCommission;
use App\Models\Affiliate\AffiliateReferral;
use App\Models\Affiliate\AffiliateSale;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliatePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_non_affiliate_cannot_access_affiliate_portal(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/dashboard')
            ->assertForbidden();
    }

    public function test_affiliate_can_view_dashboard_stats(): void
    {
        $user = User::factory()->create(['role' => 'affiliate']);
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'slug' => 'portal-user',
            'code' => 'PORTAL01',
            'commission_rate' => 7.50,
            'status' => 'active',
            'total_earnings' => 150.00,
            'total_paid' => 50.00,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'PORTAL01')
            ->assertJsonPath('data.total_earnings', '150.00')
            ->assertJsonPath('data.total_paid', '50.00')
            ->assertJsonPath('data.pending_balance', '100.00');
    }

    public function test_affiliate_can_list_referrals_with_normalized_pagination(): void
    {
        $user = User::factory()->create(['role' => 'affiliate']);
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'slug' => 'portal-referrals',
            'code' => 'PORTAL02',
            'status' => 'active',
        ]);

        $referredUser = User::factory()->create();
        AffiliateReferral::create([
            'affiliate_id' => $affiliate->id,
            'user_id' => $referredUser->id,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/referrals?per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 10)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_affiliate_can_list_sales_and_commissions_with_normalized_pagination(): void
    {
        $user = User::factory()->create(['role' => 'affiliate']);
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'slug' => 'portal-sales',
            'code' => 'PORTAL03',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Sample Product',
            'slug' => 'sample-product',
            'sku' => 'SMP-001',
            'price' => 100.00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create();

        $order = Order::create([
            'user_id' => $buyer->id,
            'number' => 'ORD-AFF-001',
            'currency' => 'NGN',
            'subtotal' => 100.00,
            'grand_total' => 100.00,
            'total_amount' => 100.00,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '123 Street'],
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Sample Product',
            'sku' => 'SMP-001',
            'selected_options' => [],
            'quantity' => 1,
            'unit_price' => 100.00,
            'price_at_purchase' => 100.00,
            'line_total' => 100.00,
        ]);

        $sale = AffiliateSale::create([
            'affiliate_id' => $affiliate->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'buyer_user_id' => $buyer->id,
            'sale_type' => 'direct',
            'sale_amount' => 100.00,
        ]);

        AffiliateCommission::create([
            'affiliate_id' => $affiliate->id,
            'affiliate_sale_id' => $sale->id,
            'commission_percent' => 5.00,
            'commission_amount' => 5.00,
            'payment_status' => 'pending',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/sales?per_page=5')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items');

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/commissions?per_page=5')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items');
    }
}
