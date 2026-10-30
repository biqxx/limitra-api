<?php

namespace Tests\Feature\Affiliate;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Affiliate\Affiliate;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Payment\Payment;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\Payment\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AffiliateSalesCommissionTest extends TestCase
{
    use RefreshDatabase;

    private Affiliate $affiliate;

    private User $affiliateUser;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->affiliateUser = User::factory()->create();
        $this->affiliate = Affiliate::create([
            'user_id' => $this->affiliateUser->id,
            'slug' => 'affiliate-pro',
            'code' => 'AFFILIATE10',
            'commission_rate' => 10.00,
            'status' => 'active',
            'total_earnings' => 0.00,
            'total_paid' => 0.00,
        ]);

        $category = Category::create([
            'name' => 'Fashion',
            'slug' => 'fashion-'.Str::random(5),
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Designer Watch',
            'slug' => 'designer-watch-'.Str::random(5),
            'price' => 2000.00,
            'stock' => 100,
            'status' => 'active',
        ]);
    }

    private function createOrderForUser(User $buyer, float $price = 2000.00, int $quantity = 2): Order
    {
        $order = Order::create([
            'user_id' => $buyer->id,
            'number' => 'LMT-ORD-'.Str::upper(Str::random(10)),
            'currency' => 'NGN',
            'subtotal' => $price * $quantity,
            'grand_total' => $price * $quantity,
            'total_amount' => $price * $quantity,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Abuja'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => 'SKU-WATCH',
            'quantity' => $quantity,
            'unit_price' => $price,
            'price_at_purchase' => $price,
            'line_total' => $price * $quantity,
        ]);

        return $order;
    }

    public function test_payment_settlement_creates_commission_for_referred_buyer(): void
    {
        $buyer = User::factory()->create([
            'referred_by' => $this->affiliate->id,
        ]);

        $order = $this->createOrderForUser($buyer, 2000.00, 2); // 4000 total

        $payment = Payment::create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => 'LMT-PAY-'.Str::upper(Str::random(10)),
            'status' => 'pending',
            'currency' => 'NGN',
            'amount' => 4000.00,
            'amount_minor' => 400000,
            'customer_email' => $buyer->email,
        ]);

        $settlement = app(PaymentSettlementService::class);
        $settlement->apply($payment, [
            'status' => 'success',
            'reference' => $payment->reference,
            'amount' => 400000,
            'currency' => 'NGN',
        ]);

        $this->assertDatabaseHas('affiliate_sales', [
            'affiliate_id' => $this->affiliate->id,
            'order_id' => $order->id,
            'buyer_user_id' => $buyer->id,
            'sale_type' => 'indirect',
            'sale_amount' => 4000.00,
        ]);

        $this->assertDatabaseHas('affiliate_commissions', [
            'affiliate_id' => $this->affiliate->id,
            'commission_percent' => 10.00,
            'commission_amount' => 400.00, // 10% of 4000
            'payment_status' => 'pending',
        ]);

        $this->affiliate->refresh();
        $this->assertEquals(400.00, (float) $this->affiliate->total_earnings);
    }

    public function test_self_referral_is_strictly_blocked(): void
    {
        // Affiliate purchasing for themselves
        $order = $this->createOrderForUser($this->affiliateUser, 1000.00, 1);

        $service = app(AffiliateService::class);
        $service->recordSales($order, $this->affiliate->code);

        $this->assertDatabaseCount('affiliate_sales', 0);
        $this->assertDatabaseCount('affiliate_commissions', 0);
        $this->assertEquals(0.00, (float) $this->affiliate->fresh()->total_earnings);
    }

    public function test_record_sales_is_idempotent(): void
    {
        $buyer = User::factory()->create([
            'referred_by' => $this->affiliate->id,
        ]);
        $order = $this->createOrderForUser($buyer, 1500.00, 2); // 3000 total

        $service = app(AffiliateService::class);

        // First execution
        $service->recordSales($order);
        $this->assertDatabaseCount('affiliate_sales', 1);
        $this->assertDatabaseCount('affiliate_commissions', 1);
        $this->assertEquals(300.00, (float) $this->affiliate->fresh()->total_earnings);

        // Second execution should do nothing
        $service->recordSales($order);
        $this->assertDatabaseCount('affiliate_sales', 1);
        $this->assertDatabaseCount('affiliate_commissions', 1);
        $this->assertEquals(300.00, (float) $this->affiliate->fresh()->total_earnings);
    }
}
