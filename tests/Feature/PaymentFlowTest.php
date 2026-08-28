<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\SavedCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'services.paystack.secret_key' => 'sk_test_payment_secret',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_payment_initialization_uses_server_amount_and_replays_idempotently(): void
    {
        [$user, $order] = $this->order();
        Http::fake(function (ClientRequest $request) {
            $this->assertSame('20250000', $request->data()['amount']);
            $this->assertSame('buyer@example.com', $request->data()['email']);
            $this->assertSame(['card'], $request->data()['channels']);
            $this->assertStringStartsWith('LMT-PAY-', $request->data()['reference']);

            return Http::response(['status' => true, 'data' => [
                'reference' => $request->data()['reference'],
                'authorization_url' => 'https://checkout.paystack.com/access-123',
                'access_code' => 'access-123',
            ]]);
        });
        $payload = [
            'order_id' => $order->id,
            'method' => 'card',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ];
        $headers = ['Idempotency-Key' => 'payment-initialize-001'];

        $first = $this->actingAs($user, 'api')->postJson('/api/v1/payments/initialize', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '202500.00')
            ->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/access-123')
            ->assertJsonPath('data.access_code', 'access-123');
        $second = $this->actingAs($user, 'api')->postJson('/api/v1/payments/initialize', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('message', 'Payment already initialized.');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount_minor' => 20250000,
            'status' => 'pending',
        ]);
        Http::assertSentCount(1);

        $this->actingAs($user, 'api')->getJson('/api/v1/payments/'.$first->json('data.id'))
            ->assertOk()
            ->assertJsonMissing(['access_code' => 'access-123']);

        $this->actingAs($user, 'api')->postJson('/api/v1/payments/initialize', array_merge($payload, [
            'callback_url' => 'https://shop.limitra.test/payment/other',
        ]), $headers)->assertConflict();
    }

    public function test_reference_status_verifies_payment_and_confirms_order(): void
    {
        [$user, $order] = $this->order();
        $payment = $this->payment($order);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
                'id' => 4099260516,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 20250000,
                'currency' => 'NGN',
                'channel' => 'card',
                'gateway_response' => 'Approved',
                'paid_at' => now()->toIso8601String(),
                'customer' => ['email' => 'buyer@example.com'],
            ]]),
        ]);

        $this->actingAs($user, 'api')->getJson("/api/v1/payments/reference/{$payment->reference}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.channel', 'card');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'succeeded',
            'provider_transaction_id' => '4099260516',
        ]);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('confirmed', $order->fresh()->status);

        Http::fake(fn () => Http::response([], 500));
        $this->actingAs($user, 'api')->getJson("/api/v1/payments/reference/{$payment->reference}/status")
            ->assertOk();
        Http::assertNothingSent();
    }

    public function test_signed_webhook_settles_once_and_invalid_signatures_are_rejected(): void
    {
        [, $order] = $this->order();
        $payment = $this->payment($order);
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 4099260517,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 20250000,
                'currency' => 'NGN',
                'channel' => 'card',
                'gateway_response' => 'Approved',
                'customer' => ['email' => 'buyer@example.com'],
            ],
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $payload, 'sk_test_payment_secret');

        $this->postRawWebhook($payload, 'invalid-signature')->assertUnauthorized();
        $this->assertDatabaseCount('payment_webhooks', 0);

        $this->postRawWebhook($payload, $signature)->assertOk();
        $this->postRawWebhook($payload, $signature)->assertOk();

        $this->assertDatabaseCount('payment_webhooks', 1);
        $this->assertDatabaseHas('payment_webhooks', [
            'provider' => 'paystack',
            'reference' => $payment->reference,
            'status' => 'processed',
        ]);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_mismatched_provider_amount_cannot_mark_an_order_paid(): void
    {
        [$user, $order] = $this->order();
        $payment = $this->payment($order);
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 100,
                'currency' => 'NGN',
                'customer' => ['email' => 'buyer@example.com'],
            ]]),
        ]);

        $this->actingAs($user, 'api')->getJson("/api/v1/payments/reference/{$payment->reference}/status")
            ->assertStatus(502)
            ->assertJsonPath('message', 'The provider amount does not match this payment.');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_failed_initialization_can_be_retried_as_a_new_attempt(): void
    {
        [$user, $order] = $this->order(method: 'bank_transfer');
        $attempt = 0;
        Http::fake(function (ClientRequest $request) use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                return Http::response(['status' => false], 422);
            }

            return Http::response(['status' => true, 'data' => [
                'reference' => $request->data()['reference'],
                'authorization_url' => 'https://checkout.paystack.com/bank-transfer',
                'access_code' => 'bank-transfer',
            ]]);
        });
        $payload = [
            'order_id' => $order->id,
            'method' => 'bank_transfer',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ];

        $this->actingAs($user, 'api')->postJson('/api/v1/payments/initialize', $payload, [
            'Idempotency-Key' => 'payment-failing-attempt',
        ])->assertStatus(502);
        $failed = Payment::firstOrFail();
        $this->assertSame('failed', $failed->status);

        $this->actingAs($user, 'api')->postJson("/api/v1/payments/{$failed->id}/retry", [
            'method' => 'bank_transfer',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ], ['Idempotency-Key' => 'payment-retry-attempt'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', [
            'parent_payment_id' => $failed->id,
            'status' => 'pending',
        ]);
    }

    public function test_reusable_saved_card_is_charged_by_authorization_without_exposing_its_token(): void
    {
        [$user, $order] = $this->order();
        $card = SavedCard::create([
            'user_id' => $user->id,
            'provider' => 'paystack',
            'authorization_code' => 'AUTH_private_code',
            'signature' => 'SIG_unique_card',
            'brand' => 'visa',
            'last4' => '4081',
            'expiry_month' => 12,
            'expiry_year' => now()->year + 2,
            'reusable' => true,
            'is_default' => true,
        ]);
        Http::fake(function (ClientRequest $request) {
            $this->assertStringEndsWith('/transaction/charge_authorization', $request->url());
            $this->assertSame('AUTH_private_code', $request->data()['authorization_code']);

            return Http::response(['status' => true, 'data' => [
                'id' => 4099260518,
                'status' => 'success',
                'reference' => $request->data()['reference'],
                'amount' => 20250000,
                'currency' => 'NGN',
                'channel' => 'card',
                'customer' => ['email' => 'buyer@example.com'],
            ]]);
        });

        $response = $this->actingAs($user, 'api')->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method' => 'card',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
            'saved_card_id' => $card->id,
        ], ['Idempotency-Key' => 'payment-saved-card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonMissing(['authorization_code' => 'AUTH_private_code']);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->actingAs($user, 'api')->getJson('/api/v1/payments/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonMissing(['authorization_code' => 'AUTH_private_code']);
    }

    public function test_unknown_provider_outcome_stays_pending_and_cannot_be_retried(): void
    {
        [$user, $order] = $this->order();
        Http::fake(fn () => Http::response([], 503));

        $this->actingAs($user, 'api')->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method' => 'card',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ], ['Idempotency-Key' => 'payment-unknown-result'])->assertStatus(502);

        $payment = Payment::firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->actingAs($user, 'api')->postJson("/api/v1/payments/{$payment->id}/retry", [
            'method' => 'card',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ], ['Idempotency-Key' => 'payment-unknown-retry'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');
    }

    public function test_payment_endpoints_enforce_order_and_attempt_ownership(): void
    {
        [, $order] = $this->order();
        $payment = $this->payment($order);
        $other = User::create([
            'username' => 'other-buyer',
            'email' => 'other@example.com',
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($other, 'api')->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method' => 'card',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ], ['Idempotency-Key' => 'foreign-order-payment'])->assertNotFound();
        $this->actingAs($other, 'api')->getJson("/api/v1/payments/{$payment->id}")->assertForbidden();
        $this->actingAs($other, 'api')->getJson("/api/v1/payments/reference/{$payment->reference}/status")->assertNotFound();
        $this->actingAs($other, 'api')->postJson("/api/v1/payments/{$payment->id}/retry", [
            'method' => 'card',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ], ['Idempotency-Key' => 'foreign-payment-retry'])->assertForbidden();
    }

    private function order(string $method = 'card'): array
    {
        $user = User::create([
            'username' => 'buyer'.fake()->unique()->numberBetween(1000, 9999),
            'email' => 'buyer@example.com',
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'number' => 'LMT-TEST-'.fake()->unique()->numberBetween(1000, 9999),
            'currency' => 'NGN',
            'subtotal' => 200000,
            'discount_total' => 0,
            'credit_total' => 0,
            'shipping_total' => 2500,
            'grand_total' => 202500,
            'total_amount' => 202500,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => $method,
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '14 Admiralty Way', 'country' => 'NG'],
        ]);

        return [$user, $order];
    }

    private function payment(Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'provider' => 'paystack',
            'method' => $order->payment_method,
            'reference' => 'LMT-PAY-TEST-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => 'pending',
            'currency' => 'NGN',
            'amount' => 202500,
            'amount_minor' => 20250000,
            'customer_email' => 'buyer@example.com',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ]);
    }

    private function postRawWebhook(string $payload, string $signature)
    {
        return $this->call('POST', '/api/v1/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $payload);
    }
}
