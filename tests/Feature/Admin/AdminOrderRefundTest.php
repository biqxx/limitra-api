<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\User;
use App\Services\Payment\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();

        $this->order = Order::create([
            'user_id' => $buyer->id,
            'number' => 'LMT-REFORD-'.Str::upper(Str::random(10)),
            'currency' => 'NGN',
            'subtotal' => 5000.00,
            'grand_total' => 5000.00,
            'total_amount' => 5000.00,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Lagos'],
        ]);

        $this->payment = Payment::create([
            'order_id' => $this->order->id,
            'user_id' => $buyer->id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => 'LMT-PAY-'.Str::upper(Str::random(12)),
            'status' => 'succeeded',
            'currency' => 'NGN',
            'amount' => 5000.00,
            'amount_minor' => 500000,
            'customer_email' => $buyer->email,
        ]);
    }

    public function test_unauthenticated_and_unauthorized_users_are_forbidden(): void
    {
        $this->postJson("/api/v1/admin/orders/{$this->order->id}/refund")
            ->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/admin/orders/{$this->order->id}/refund")
            ->assertForbidden();
    }

    public function test_idempotency_key_is_required(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/orders/{$this->order->id}/refund", [
                'amount' => 2500.00,
                'method' => 'original_payment',
                'reason' => 'Customer requested partial refund',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_admin_can_refund_paid_order_and_replay_idempotently(): void
    {
        Http::fake([
            'https://api.paystack.co/refund' => Http::response([
                'status' => true,
                'message' => 'Refund has been queued for processing',
                'data' => [
                    'id' => 888123,
                    'status' => 'processed',
                    'reference' => 'PSTK-REF-'.Str::random(10),
                    'amount' => 500000,
                    'currency' => 'NGN',
                    'transaction' => [
                        'reference' => $this->payment->reference,
                    ],
                ],
            ], 200),
        ]);

        $idempotencyKey = 'refund-key-'.Str::uuid();

        // 1. Initial refund
        $response = $this->actingAs($this->admin, 'api')
            ->postJson(
                "/api/v1/admin/orders/{$this->order->id}/refund",
                [
                    'amount' => 5000.00,
                    'method' => 'original_payment',
                    'reason' => 'Damaged package on delivery',
                ],
                ['Idempotency-Key' => $idempotencyKey]
            );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '5000.00')
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.reason', 'Damaged package on delivery');

        $this->order->refresh();
        $this->assertEquals('refunded', $this->order->payment_status);

        // Audit log verified
        $this->assertDatabaseHas('audit_events', [
            'action' => 'order.refunded',
            'subject_id' => $this->order->id,
            'actor_id' => $this->admin->id,
        ]);

        // 2. Replay with same idempotency key
        $replayResponse = $this->actingAs($this->admin, 'api')
            ->postJson(
                "/api/v1/admin/orders/{$this->order->id}/refund",
                [
                    'amount' => 5000.00,
                    'method' => 'original_payment',
                    'reason' => 'Damaged package on delivery',
                ],
                ['Idempotency-Key' => $idempotencyKey]
            );

        $replayResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '5000.00')
            ->assertJsonPath('message', 'Refund request already submitted.');

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_admin_can_refund_wallet_only_order_to_wallet_balance(): void
    {
        $buyer = User::factory()->create();
        $walletOrder = Order::create([
            'user_id' => $buyer->id,
            'number' => 'LMT-WLTORD-'.Str::upper(Str::random(10)),
            'currency' => 'NGN',
            'subtotal' => 4000.00,
            'credit_total' => 4000.00,
            'grand_total' => 0.00,
            'total_amount' => 0.00,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfilment_status' => 'delivered',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Abuja'],
        ]);

        $idempotencyKey = 'wallet-refund-'.Str::uuid();

        $response = $this->actingAs($this->admin, 'api')
            ->postJson(
                "/api/v1/admin/orders/{$walletOrder->id}/refund",
                [
                    'amount' => 4000.00,
                    'method' => 'wallet',
                    'reason' => 'Defective item returned on wallet order',
                ],
                ['Idempotency-Key' => $idempotencyKey]
            );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '4000.00')
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.method', 'wallet');

        $walletOrder->refresh();
        $this->assertSame('refunded', $walletOrder->payment_status);

        $this->assertDatabaseHas('refunds', [
            'order_id' => $walletOrder->id,
            'payment_id' => null,
            'provider' => 'wallet',
            'method' => 'wallet',
            'status' => 'processed',
        ]);

        $account = $buyer->account()->first();
        $this->assertNotNull($account);
        $this->assertEquals(400000, $account->cash_balance_minor);

        $this->assertDatabaseHas('wallet_transactions', [
            'account_id' => $account->id,
            'direction' => 'credit',
            'type' => 'refund',
            'amount_minor' => 400000,
        ]);
    }

    public function test_payment_settlement_replay_does_not_overwrite_refunded_order(): void
    {
        $this->order->update(['payment_status' => 'refunded']);

        $settlement = app(PaymentSettlementService::class);
        $settlement->apply($this->payment, [
            'status' => 'success',
            'amount' => 500000,
            'currency' => 'NGN',
            'reference' => $this->payment->reference,
        ]);

        $this->order->refresh();
        $this->assertSame('refunded', $this->order->payment_status);

        $settlement->apply($this->payment, [
            'status' => 'failed',
            'amount' => 500000,
            'currency' => 'NGN',
            'reference' => $this->payment->reference,
        ]);

        $this->order->refresh();
        $this->assertSame('refunded', $this->order->payment_status);
    }
}
