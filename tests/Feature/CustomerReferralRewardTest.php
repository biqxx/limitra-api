<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\Shipment;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Referral\CustomerReferral;
use App\Models\User;
use App\Notifications\ReferralRewardEarnedNotification;
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
