<?php

namespace Tests\Feature\Affiliate;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Address\Address;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateLink;
use App\Models\Affiliate\AffiliatePayoutMethod;
use App\Models\Affiliate\AffiliatePromoCode;
use App\Models\Affiliate\AffiliateWithdrawal;
use App\Models\Cart\Cart;
use App\Models\Commerce\CheckoutQuote;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\Promotion;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Payment\Payment;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Affiliate\AffiliateAttributionService;
use App\Services\Order\OrderLifecycleService;
use App\Services\Payment\PaymentSettlementService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AffiliateFinancialIntegrityAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    private User $affiliateUser;

    private Affiliate $affiliate;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Administrator', 'description' => 'Full admin access']
        );

        $this->adminA = User::factory()->create(['role' => 'admin']);
        $this->adminB = User::factory()->create(['role' => 'admin']);

        $this->affiliateUser = User::factory()->create(['role' => 'affiliate']);
        $this->affiliate = Affiliate::create([
            'user_id' => $this->affiliateUser->id,
            'slug' => 'top-affiliate',
            'code' => 'TOPAFF01',
            'commission_rate' => 10.00,
            'current_tier' => 'Bronze',
            'status' => 'active',
            'total_earnings' => 0.00,
            'total_paid' => 0.00,
        ]);
    }

    public function test_payout_methods_encrypt_account_number_at_rest_and_only_return_masked_details(): void
    {
        $response = $this->actingAs($this->affiliateUser, 'api')
            ->postJson('/api/v1/affiliate/payout-methods', [
                'bank_name' => 'Access Bank',
                'bank_code' => '044',
                'account_number' => '0123456789',
                'account_name' => 'Top Affiliate Ltd',
                'is_default' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.bank_name', 'Access Bank')
            ->assertJsonPath('data.account_number_masked', '******6789')
            ->assertJsonMissing(['account_number' => '0123456789']);

        // Check raw database record is encrypted and does not contain plain text
        $rawDbRecord = DB::table('affiliate_payout_methods')
            ->where('affiliate_id', $this->affiliate->id)
            ->first();

        $this->assertNotNull($rawDbRecord);
        $this->assertNotEquals('0123456789', $rawDbRecord->account_number);
        $this->assertStringNotContainsString('0123456789', $rawDbRecord->account_number);

        // Model decrypts properly
        $model = AffiliatePayoutMethod::find($rawDbRecord->id);
        $this->assertEquals('0123456789', $model->account_number);
        $this->assertEquals('******6789', $model->masked_account_number);
    }

    public function test_cannot_delete_payout_method_referenced_by_pending_or_completed_withdrawal(): void
    {
        $method = AffiliatePayoutMethod::create([
            'affiliate_id' => $this->affiliate->id,
            'bank_name' => 'Zenith Bank',
            'account_number' => '1234567890',
            'account_name' => 'Top Affiliate Ltd',
            'is_default' => true,
        ]);

        $withdrawal = AffiliateWithdrawal::create([
            'affiliate_id' => $this->affiliate->id,
            'payout_method_id' => $method->id,
            'amount' => 5000.00,
            'currency' => 'NGN',
            'status' => 'pending',
            'reference' => 'WTH-TEST-001',
        ]);

        // Attempt deletion of payout method referenced by pending withdrawal -> 422
        $this->actingAs($this->affiliateUser, 'api')
            ->deleteJson("/api/v1/affiliate/payout-methods/{$method->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete a payout method that has associated pending or processed withdrawals.');

        // Delete works once the withdrawal is not blocking (e.g. unreferenced method)
        $unreferenced = AffiliatePayoutMethod::create([
            'affiliate_id' => $this->affiliate->id,
            'bank_name' => 'UBA',
            'account_number' => '9876543210',
            'account_name' => 'Secondary Account',
            'is_default' => false,
        ]);

        $this->actingAs($this->affiliateUser, 'api')
            ->deleteJson("/api/v1/affiliate/payout-methods/{$unreferenced->id}")
            ->assertOk();

        $this->assertDatabaseMissing('affiliate_payout_methods', ['id' => $unreferenced->id]);
    }

    public function test_cannot_request_withdrawal_with_another_affiliates_payout_method(): void
    {
        $otherUser = User::factory()->create(['role' => 'affiliate']);
        $otherAffiliate = Affiliate::create([
            'user_id' => $otherUser->id,
            'slug' => 'other-affiliate',
            'code' => 'OTHER01',
            'status' => 'active',
        ]);

        $otherMethod = AffiliatePayoutMethod::create([
            'affiliate_id' => $otherAffiliate->id,
            'bank_name' => 'GTBank',
            'account_number' => '1122334455',
            'account_name' => 'Other Account',
            'is_default' => true,
        ]);

        $this->actingAs($this->affiliateUser, 'api')
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 5000.00,
                'payout_method_id' => $otherMethod->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected payout method does not belong to your account.');
    }

    public function test_bank_account_verification_endpoint(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_fake_secret_key']);

        Http::fake([
            'https://api.paystack.co/bank/resolve*' => Http::response([
                'status' => true,
                'message' => 'Account number resolved',
                'data' => [
                    'account_number' => '0123456789',
                    'account_name' => 'JOHN DOE VENTURES',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->affiliateUser, 'api')
            ->postJson('/api/v1/affiliate/payout-methods/verify', [
                'bank_code' => '044',
                'account_number' => '0123456789',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_name', 'JOHN DOE VENTURES')
            ->assertJsonPath('data.is_verified', true);
    }

    public function test_authoritative_available_balance_and_balance_hold_prevents_concurrent_over_withdrawal(): void
    {
        // Setup initial earnings of 50,000 NGN
        $this->affiliate->update([
            'total_earnings' => 50000.00,
            'total_paid' => 0.00,
        ]);

        $method = AffiliatePayoutMethod::create([
            'affiliate_id' => $this->affiliate->id,
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'Top Affiliate Ltd',
            'is_default' => true,
        ]);

        // First withdrawal of 30,000 NGN
        $res1 = $this->actingAs($this->affiliateUser, 'api')
            ->withHeader('Idempotency-Key', 'wth-key-1')
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 30000.00,
                'payout_method_id' => $method->id,
            ]);

        $res1->assertCreated()
            ->assertJsonPath('data.amount', '30000.00')
            ->assertJsonPath('data.status', 'pending');

        // Check hold ledger entry created
        $this->assertDatabaseHas('affiliate_ledger_entries', [
            'affiliate_id' => $this->affiliate->id,
            'type' => 'hold',
            'amount' => -30000.00,
        ]);

        // Authoritative available balance is now 20,000 NGN
        $this->assertEquals(20000.00, $this->affiliate->getAuthoritativeAvailableBalance());

        // Second withdrawal for 25,000 NGN must be rejected because only 20,000 is available
        $res2 = $this->actingAs($this->affiliateUser, 'api')
            ->withHeader('Idempotency-Key', 'wth-key-2')
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 25000.00,
                'payout_method_id' => $method->id,
            ]);

        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_withdrawal_idempotency_key_replays_safely_and_detects_tampered_payload(): void
    {
        $this->affiliate->update([
            'total_earnings' => 40000.00,
            'total_paid' => 0.00,
        ]);

        $method = AffiliatePayoutMethod::create([
            'affiliate_id' => $this->affiliate->id,
            'bank_name' => 'First Bank',
            'account_number' => '1112223334',
            'account_name' => 'Top Affiliate Ltd',
            'is_default' => true,
        ]);

        $headers = ['Idempotency-Key' => 'unique-withdraw-idemp-1'];

        // Request 1
        $res1 = $this->actingAs($this->affiliateUser, 'api')
            ->withHeaders($headers)
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 10000.00,
                'payout_method_id' => $method->id,
            ]);

        $res1->assertCreated();
        $withdrawalId = $res1->json('data.id');

        // Replay same request with same idempotency key -> returns existing withdrawal
        $resReplay = $this->actingAs($this->affiliateUser, 'api')
            ->withHeaders($headers)
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 10000.00,
                'payout_method_id' => $method->id,
            ]);

        $resReplay->assertOk()
            ->assertJsonPath('data.id', $withdrawalId);

        // Same idempotency key with modified amount -> 409 conflict
        $resTampered = $this->actingAs($this->affiliateUser, 'api')
            ->withHeaders($headers)
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 15000.00,
                'payout_method_id' => $method->id,
            ]);

        $resTampered->assertStatus(409)
            ->assertJsonPath('message', 'Idempotency key reused with different request payload.');
    }

    public function test_withdrawal_strict_state_machine_and_cannot_mark_paid_from_pending(): void
    {
        $this->affiliate->update([
            'total_earnings' => 50000.00,
            'total_paid' => 0.00,
        ]);

        $method = AffiliatePayoutMethod::create([
            'affiliate_id' => $this->affiliate->id,
            'bank_name' => 'Zenith Bank',
            'account_number' => '5566778899',
            'account_name' => 'Top Affiliate',
            'is_default' => true,
        ]);

        $wthRes = $this->actingAs($this->affiliateUser, 'api')
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 10000.00,
                'payout_method_id' => $method->id,
            ]);

        $withdrawalId = $wthRes->json('data.id');
        $withdrawal = AffiliateWithdrawal::find($withdrawalId);

        // Admin attempting mark-paid directly from pending -> 422
        $this->actingAs($this->adminA, 'api')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/mark-paid", [
                'provider_reference' => 'TXN-BANK-001',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Withdrawal must be approved before it can be marked as paid.');

        // Step 1: Admin A approves
        $this->actingAs($this->adminA, 'api')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/approve", [
                'admin_notes' => 'Approved by Finance',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Step 2: Separation of duties check:
        // Admin A who approved cannot mark it as paid!
        $this->actingAs($this->adminA, 'api')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/mark-paid", [
                'provider_reference' => 'TXN-BANK-001',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Separation of duties required: approval and payment processing must be performed by different administrators.');

        // Step 3: Admin B marks as paid
        $paidRes = $this->actingAs($this->adminB, 'api')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/mark-paid", [
                'provider_reference' => 'TXN-BANK-001',
            ]);

        $paidRes->assertOk()
            ->assertJsonPath('data.withdrawal.status', 'paid')
            ->assertJsonPath('data.withdrawal.provider_reference', 'TXN-BANK-001');

        $this->assertDatabaseHas('affiliate_ledger_entries', [
            'affiliate_id' => $this->affiliate->id,
            'type' => 'settlement',
        ]);

        $this->assertEquals(10000.00, (float) $this->affiliate->fresh()->total_paid);
    }

    public function test_rejecting_withdrawal_releases_balance_hold_back_to_authoritative_balance(): void
    {
        $this->affiliate->update([
            'total_earnings' => 50000.00,
            'total_paid' => 0.00,
        ]);

        $method = AffiliatePayoutMethod::create([
            'affiliate_id' => $this->affiliate->id,
            'bank_name' => 'Zenith Bank',
            'account_number' => '5566778899',
            'account_name' => 'Top Affiliate',
            'is_default' => true,
        ]);

        $wthRes = $this->actingAs($this->affiliateUser, 'api')
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 20000.00,
                'payout_method_id' => $method->id,
            ]);

        $withdrawal = AffiliateWithdrawal::find($wthRes->json('data.id'));

        // Available balance is held (reduced to 30,000)
        $this->assertEquals(30000.00, $this->affiliate->getAuthoritativeAvailableBalance());

        // Admin rejects the withdrawal
        $this->actingAs($this->adminA, 'api')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/reject", [
                'reason' => 'Invalid account details provided',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // Compensating release entry is recorded
        $this->assertDatabaseHas('affiliate_ledger_entries', [
            'affiliate_id' => $this->affiliate->id,
            'type' => 'release',
            'amount' => 20000.00,
        ]);

        // Available balance is fully restored to 50,000
        $this->assertEquals(50000.00, $this->affiliate->getAuthoritativeAvailableBalance());
    }

    public function test_public_tracking_link_resolution_and_open_redirect_protection(): void
    {
        $link = AffiliateLink::create([
            'affiliate_id' => $this->affiliate->id,
            'slug' => 'summer-sale-2026',
            'landing_page' => 'https://limitra.test/deals/summer',
            'is_active' => true,
        ]);

        // Valid allowlisted redirect
        $res = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->get('/l/summer-sale-2026');

        $res->assertRedirect('https://limitra.test/deals/summer')
            ->assertCookie(AffiliateAttributionService::COOKIE_NAME);

        $this->assertDatabaseHas('affiliate_clicks', [
            'affiliate_link_id' => $link->id,
            'destination_url' => 'https://limitra.test/deals/summer',
            'is_bot' => false,
        ]);

        // Open redirect attack attempt: malicious external URL
        $maliciousLink = AffiliateLink::create([
            'affiliate_id' => $this->affiliate->id,
            'slug' => 'malicious-phishing-link',
            'landing_page' => 'https://evil-hacker-site.ru/steal-credentials',
            'is_active' => true,
        ]);

        $resMalicious = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->get('/l/malicious-phishing-link');

        // Redirects safely to '/' instead of untrusted host!
        $resMalicious->assertRedirect('/');

        // Bot test: bot user agents do not set cookie
        $resBot = $this->withHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])
            ->get('/l/summer-sale-2026');

        $resBot->assertCookieMissing(AffiliateAttributionService::COOKIE_NAME);
        $this->assertDatabaseHas('affiliate_clicks', [
            'affiliate_link_id' => $link->id,
            'is_bot' => true,
        ]);
    }

    public function test_deterministic_precedence_promo_code_over_tracked_link_over_referral(): void
    {
        // Affiliate 1: Has promo code
        $affiliate1 = $this->affiliate;
        $promo = Promotion::create([
            'code' => 'AFFPROMO10',
            'name' => '10% Affiliate Promo',
            'type' => 'percentage',
            'value' => 10.00,
            'active' => true,
        ]);
        $promoCode = AffiliatePromoCode::create([
            'affiliate_id' => $affiliate1->id,
            'promotion_id' => $promo->id,
            'code' => 'AFFPROMO10',
            'discount_percent' => 10.00,
            'is_active' => true,
        ]);

        // Affiliate 2: Tracking link click
        $user2 = User::factory()->create(['role' => 'affiliate']);
        $affiliate2 = Affiliate::create([
            'user_id' => $user2->id,
            'slug' => 'affiliate-two',
            'code' => 'AFFTWO02',
            'commission_rate' => 10.00,
            'status' => 'active',
        ]);
        $link2 = AffiliateLink::create([
            'affiliate_id' => $affiliate2->id,
            'slug' => 'afftwo-link',
            'is_active' => true,
        ]);

        // Affiliate 3: User's original recruiter
        $user3 = User::factory()->create(['role' => 'affiliate']);
        $affiliate3 = Affiliate::create([
            'user_id' => $user3->id,
            'slug' => 'affiliate-three',
            'code' => 'AFFTHREE03',
            'commission_rate' => 10.00,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create([
            'referred_by' => $affiliate3->id, // Priority 3
        ]);

        $category = Category::create(['name' => 'Audio', 'slug' => 'audio-'.Str::random(5), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Headphones',
            'slug' => 'headphones-'.Str::random(5),
            'price' => 1000.00,
            'stock' => 50,
            'status' => 'active',
        ]);

        // Create cookie payload for Affiliate 2 (Priority 2)
        $cookieToken = Crypt::encryptString(json_encode([
            'affiliate_id' => $affiliate2->id,
            'link_id' => $link2->id,
            'timestamp' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        $cart = Cart::create([
            'user_id' => $buyer->id,
            'session_id' => (string) Str::uuid(),
        ]);

        $address = Address::create([
            'user_id' => $buyer->id,
            'type' => 'delivery',
            'label' => 'Home',
            'recipient_name' => $buyer->name ?? 'Buyer Name',
            'phone' => '+2348012345678',
            'line1' => '12 Marina Street',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'NG',
            'postal_code' => '100001',
            'is_default' => true,
        ]);

        $deliveryMethod = DeliveryMethod::firstOrCreate(
            ['code' => 'standard'],
            ['name' => 'Standard Delivery', 'type' => 'standard', 'active' => true]
        );

        // Create quote with promo code (Priority 1)
        $quote = CheckoutQuote::create([
            'cart_id' => $cart->id,
            'address_id' => $address->id,
            'delivery_method_id' => $deliveryMethod->id,
            'user_id' => $buyer->id,
            'promotion_id' => $promo->id,
            'currency' => 'NGN',
            'subtotal' => 2000.00,
            'discount_total' => 200.00,
            'shipping_total' => 0.00,
            'grand_total' => 1800.00,
            'payment_method' => 'card',
            'address_snapshot' => ['city' => 'Lagos', 'line1' => '12 Marina Street'],
            'shipping_snapshot' => ['code' => 'standard', 'name' => 'Standard Delivery'],
            'expires_at' => now()->addMinutes(30),
        ]);

        $order = Order::create([
            'user_id' => $buyer->id,
            'checkout_quote_id' => $quote->id,
            'number' => 'ORD-PRECEDENCE-001',
            'currency' => 'NGN',
            'subtotal' => 2000.00,
            'grand_total' => 1800.00,
            'total_amount' => 1800.00,
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Lagos'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => 'SKU-HP',
            'quantity' => 2,
            'unit_price' => 1000.00,
            'price_at_purchase' => 1000.00,
            'line_total' => 2000.00,
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => 'PAY-PRECEDENCE-001',
            'status' => 'pending',
            'currency' => 'NGN',
            'amount' => 1800.00,
            'amount_minor' => 180000,
            'customer_email' => $buyer->email,
        ]);

        // Settle payment
        $settlement = app(PaymentSettlementService::class);
        $settlement->apply($payment, [
            'status' => 'success',
            'reference' => $payment->reference,
            'amount' => 180000,
            'currency' => 'NGN',
        ]);

        // Attribute conversion using attribution service with all 3 priorities active:
        $attributionService = app(AffiliateAttributionService::class);
        $attributionService->attributeConversion($order, null, $cookieToken);

        // Precedence assertion: Affiliate 1 (Promo Code) must be credited!
        $this->assertDatabaseHas('affiliate_sales', [
            'affiliate_id' => $affiliate1->id,
            'order_id' => $order->id,
            'affiliate_promo_code_id' => $promoCode->id,
        ]);

        // Neither Affiliate 2 nor Affiliate 3 earned the sale
        $this->assertDatabaseMissing('affiliate_sales', ['affiliate_id' => $affiliate2->id, 'order_id' => $order->id]);
        $this->assertDatabaseMissing('affiliate_sales', ['affiliate_id' => $affiliate3->id, 'order_id' => $order->id]);
    }

    public function test_order_cancellation_and_refund_reverses_commissions_with_compensating_ledger_records(): void
    {
        $buyer = User::factory()->create();
        $category = Category::create(['name' => 'Fashion', 'slug' => 'fashion-'.Str::random(5), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Sneakers',
            'slug' => 'sneakers-'.Str::random(5),
            'price' => 5000.00,
            'stock' => 20,
            'status' => 'active',
        ]);

        $order = Order::create([
            'user_id' => $buyer->id,
            'number' => 'ORD-REVERSAL-001',
            'currency' => 'NGN',
            'subtotal' => 5000.00,
            'grand_total' => 5000.00,
            'total_amount' => 5000.00,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Abuja'],
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => 'SKU-SNK',
            'quantity' => 1,
            'unit_price' => 5000.00,
            'price_at_purchase' => 5000.00,
            'line_total' => 5000.00,
        ]);

        $attributionService = app(AffiliateAttributionService::class);
        $attributionService->attributeConversion($order, $this->affiliate->code);

        // Earnings credited: 10% of 5000 = 500
        $this->assertEquals(500.00, (float) $this->affiliate->fresh()->total_earnings);
        $this->assertEquals(500.00, $this->affiliate->fresh()->getAuthoritativeAvailableBalance());

        // Cancel order -> commissions reversed
        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->cancel($order, $buyer->id, 'Customer changed mind');

        // Ledger reversal entry recorded
        $this->assertDatabaseHas('affiliate_ledger_entries', [
            'affiliate_id' => $this->affiliate->id,
            'type' => 'reversal',
            'amount' => -500.00,
        ]);

        // Balance restored to 0
        $this->assertEquals(0.00, (float) $this->affiliate->fresh()->total_earnings);
        $this->assertEquals(0.00, $this->affiliate->fresh()->getAuthoritativeAvailableBalance());
    }

    public function test_promotable_products_with_commission_preview(): void
    {
        $category = Category::create(['name' => 'Computers', 'slug' => 'computers-'.Str::random(5), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'MacBook Pro',
            'slug' => 'macbook-pro-'.Str::random(5),
            'price' => 100000.00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->affiliateUser, 'api')
            ->getJson('/api/v1/affiliate/promotable-products');

        $response->assertOk()
            ->assertJsonPath('success', true);
        $this->assertEquals(10.00, $response->json('data.commission_rate'));

        $items = $response->json('data.items');
        $found = collect($items)->firstWhere('id', $product->id);
        $this->assertNotNull($found);
        $this->assertEquals(100000.00, $found['price']);
        $this->assertEquals(10000.00, $found['estimated_commission']); // 10% of 100,000
    }

    public function test_admin_affiliate_rules_management(): void
    {
        // Get rules
        $this->actingAs($this->adminA, 'api')
            ->getJson('/api/v1/admin/affiliate-rules')
            ->assertOk()
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.attribution_window_days', 30);

        // Update rules
        $this->actingAs($this->adminA, 'api')
            ->patchJson('/api/v1/admin/affiliate-rules', [
                'min_withdrawal_amount' => 10000,
                'attribution_window_days' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('data.min_withdrawal_amount', 10000)
            ->assertJsonPath('data.attribution_window_days', 45);

        $settings = app(BusinessSettingsService::class);
        $this->assertEquals(10000, $settings->value('affiliates.min_withdrawal_amount'));
        $this->assertEquals(45, $settings->value('affiliates.attribution_window_days'));
    }
}
