<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\Shipment;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Referral\CustomerReferral;
use App\Models\User;
use App\Notifications\ReferralRewardEarnedNotification;
use App\Notifications\ReferralRewardReversedNotification;
use App\Services\Payment\RefundSettlementService;
use App\Services\Payment\WalletLedgerService;
use App\Services\Referral\ReferralRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerReferralRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Cache::flush();
        Notification::fake();
    }

    public function test_first_delivered_cash_on_delivery_order_rewards_referrer_once(): void
    {
        [$referrer, $customer, $order, $referral] = $this->scenario('cash_on_delivery', 'unpaid');

        $this->deliver($order);

        $referral = $referral->fresh();
        $this->assertSame('rewarded', $referral->status);
        $this->assertSame($order->id, $referral->qualifying_order_id);
        $this->assertSame(700000, $referral->reward_amount_minor);
        $this->assertSame('NGN', $referral->reward_currency);
        $this->assertNotNull($referral->qualified_at);
        $this->assertNotNull($referral->rewarded_at);
        $this->assertDatabaseHas('accounts', [
            'user_id' => $referrer->id,
            'lim_cash_balance_minor' => 700000,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $referral->reward_transaction_id,
            'type' => 'referral_reward',
            'balance_type' => 'lim_cash',
            'direction' => 'credit',
            'amount_minor' => 700000,
            'unique_key' => "referral_reward:{$referral->id}",
            'source_type' => 'customer_referral',
            'source_id' => $referral->id,
        ]);
        Notification::assertSentTo($referrer, ReferralRewardEarnedNotification::class, 1);

        app(ReferralRewardService::class)->grantForDeliveredOrder($order->fresh());

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(700000, $referrer->account()->value('lim_cash_balance_minor'));
        Notification::assertSentTo($referrer, ReferralRewardEarnedNotification::class, 1);
        $this->assertSame($customer->id, $referral->referred_user_id);
    }

    public function test_reward_uses_the_current_database_backed_amount(): void
    {
        DB::table('business_settings')
            ->where('key', 'referrals.reward_amount_minor')
            ->update(['value' => json_encode(925000, JSON_THROW_ON_ERROR)]);
        Cache::forget('business_settings.values.v1');
        [$referrer, , $order, $referral] = $this->scenario('card', 'paid');

        $this->deliver($order);

        $this->assertSame(925000, $referral->fresh()->reward_amount_minor);
        $this->assertSame(925000, $referrer->account()->value('lim_cash_balance_minor'));
        $this->assertDatabaseHas('wallet_transactions', [
            'unique_key' => "referral_reward:{$referral->id}",
            'amount_minor' => 925000,
        ]);
    }

    public function test_disabled_referrals_do_not_issue_rewards(): void
    {
        DB::table('business_settings')
            ->where('key', 'referrals.enabled')
            ->update(['value' => json_encode(false, JSON_THROW_ON_ERROR)]);
        Cache::forget('business_settings.values.v1');
        [$referrer, , $order, $referral] = $this->scenario('cash_on_delivery', 'unpaid');

        $this->deliver($order);

        $this->assertSame('pending', $referral->fresh()->status);
        $this->assertFalse($referrer->account()->exists());
        $this->assertDatabaseCount('wallet_transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_unpaid_online_order_is_not_a_genuine_qualifying_order(): void
    {
        [$referrer, , $order, $referral] = $this->scenario('card', 'unpaid');

        $this->deliver($order);

        $this->assertSame('pending', $referral->fresh()->status);
        $this->assertFalse($referrer->account()->exists());
        $this->assertDatabaseCount('wallet_transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_full_refund_reverses_reward_once_even_when_balance_becomes_negative(): void
    {
        [$referrer, $customer, $order, $referral] = $this->scenario('card', 'paid');
        $this->deliver($order);
        $rewardTransaction = $referral->fresh()->rewardTransaction;
        app(WalletLedgerService::class)->debit(
            $referrer,
            600000,
            'lim_cash',
            'purchase',
            "test_referral_spend:{$referral->id}",
            'Test purchase with referral reward.',
        );
        $payment = $this->payment($order, $customer);
        $firstRefund = $this->refund($payment, 5000000);
        $secondRefund = $this->refund($payment, 5250000);
        $settlement = app(RefundSettlementService::class);

        $settlement->apply($firstRefund, $this->providerRefund($firstRefund));

        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->assertSame('rewarded', $referral->fresh()->status);
        $this->assertDatabaseMissing('wallet_transactions', [
            'reverses_transaction_id' => $rewardTransaction->id,
        ]);

        $settlement->apply($secondRefund, $this->providerRefund($secondRefund));
        $settlement->apply($secondRefund->fresh(), $this->providerRefund($secondRefund));

        $referral = $referral->fresh();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('reversed', $referral->status);
        $this->assertNotNull($referral->reversed_at);
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $referral->reversal_transaction_id,
            'type' => 'reversal',
            'direction' => 'debit',
            'amount_minor' => 700000,
            'balance_after_minor' => -600000,
            'unique_key' => "referral_reward_reversal:{$referral->id}",
            'reverses_transaction_id' => $rewardTransaction->id,
        ]);
        $this->assertSame(-600000, $referrer->account()->value('lim_cash_balance_minor'));
        $this->assertSame(1, $rewardTransaction->reversal()->count());
        $this->assertDatabaseCount('wallet_transactions', 3);
        Notification::assertSentTo($referrer, ReferralRewardReversedNotification::class, 1);
    }

    /** @return array{User, User, Order, CustomerReferral} */
    private function scenario(string $paymentMethod, string $paymentStatus): array
    {
        $referrer = $this->user('referrer');
        $customer = $this->user('customer');
        $category = Category::query()->create([
            'name' => 'Referral products',
            'slug' => fake()->unique()->slug(),
            'active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Referral reward product',
            'slug' => fake()->unique()->slug(),
            'sku' => fake()->unique()->bothify('REF-####'),
            'price' => 100000,
            'currency' => 'NGN',
            'stock' => 10,
            'status' => 'active',
        ]);
        $order = Order::query()->create([
            'user_id' => $customer->id,
            'number' => fake()->unique()->bothify('LMT-REF-####'),
            'currency' => 'NGN',
            'subtotal' => 100000,
            'discount_total' => 0,
            'credit_total' => 0,
            'shipping_total' => 2500,
            'grand_total' => 102500,
            'total_amount' => 102500,
            'status' => 'in_transit',
            'payment_status' => $paymentStatus,
            'fulfilment_status' => 'in_transit',
            'payment_method' => $paymentMethod,
            'contact_email' => $customer->email,
            'delivery_method' => 'standard',
            'shipping_address' => [
                'recipient_name' => 'Referral Customer',
                'line1' => '14 Admiralty Way',
                'city' => 'Ikeja',
                'state' => 'Lagos',
                'country' => 'NG',
            ],
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'selected_options' => [],
            'quantity' => 1,
            'unit_price' => 100000,
            'price_at_purchase' => 100000,
            'line_total' => 100000,
        ]);
        Shipment::query()->create([
            'order_id' => $order->id,
            'courier' => 'DHL',
            'tracking_number' => fake()->unique()->bothify('DHL-REF-####'),
            'status' => 'in_transit',
            'shipped_at' => now()->subDay(),
        ]);
        $referral = CustomerReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $customer->id,
            'status' => 'pending',
        ]);

        return [$referrer, $customer, $order, $referral];
    }

    private function deliver(Order $order): void
    {
        $admin = $this->user('admin', 'admin');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => 'delivered',
                'note' => 'Delivered to the customer.',
                'location' => 'Ikeja',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
    }

    private function payment(Order $order, User $customer): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => fake()->unique()->bothify('PAY-REF-########'),
            'status' => 'succeeded',
            'currency' => 'NGN',
            'amount' => 102500,
            'amount_minor' => 10250000,
            'customer_email' => $customer->email,
            'paid_at' => now()->subDays(2),
            'verified_at' => now()->subDays(2),
        ]);
    }

    private function refund(Payment $payment, int $amountMinor): Refund
    {
        return Refund::query()->create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'reference' => fake()->unique()->bothify('LMT-REFUND-########'),
            'provider' => 'paystack',
            'method' => 'original_payment',
            'status' => 'pending',
            'currency' => 'NGN',
            'amount' => number_format($amountMinor / 100, 2, '.', ''),
            'amount_minor' => $amountMinor,
            'source' => 'return',
            'reason' => 'Approved return refund.',
        ]);
    }

    /** @return array<string, mixed> */
    private function providerRefund(Refund $refund): array
    {
        return [
            'id' => 'provider-'.$refund->id,
            'refund_reference' => 'provider-refund-'.$refund->id,
            'transaction_reference' => $refund->payment->reference,
            'amount' => $refund->amount_minor,
            'currency' => $refund->currency,
            'status' => 'processed',
            'refunded_at' => now()->toIso8601String(),
        ];
    }

    private function user(string $name, string $role = 'user'): User
    {
        return User::query()->create([
            'username' => $name.'-'.fake()->unique()->numerify('####'),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
