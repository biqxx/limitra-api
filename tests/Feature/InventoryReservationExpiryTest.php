<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutomaticRefund;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Notifications\AutomaticRefundAttentionNotification;
use App\Notifications\AutomaticRefundInitiatedNotification;
use App\Notifications\AutomaticRefundProcessedNotification;
use App\Notifications\AutomaticRefundStaffAlert;
use App\Notifications\InventoryReservationExpiredNotification;
use App\Services\Notification\RefundNotificationService;
use App\Services\Order\InventoryReservationService;
use App\Services\Payment\PaymentSettlementService;
use App\Services\Payment\RefundSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryReservationExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'maintenance.prune_batch_size' => 100,
        ]);
    }

    public function test_expired_online_order_releases_stock_and_is_cancelled(): void
    {
        [$order, $product] = $this->reservedOrder(now()->subMinute());
        Notification::fake();

        $released = app(InventoryReservationService::class)->releaseExpired();

        $this->assertSame(1, $released);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'released',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'failed',
            'fulfilment_status' => 'cancelled',
            'cancellation_code' => InventoryReservationService::EXPIRY_CANCELLATION_CODE,
        ]);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => 'pending_payment',
            'to_status' => 'cancelled',
            'source' => 'system',
        ]);
        $this->assertNotNull($order->fresh()->reservation_expired_notification_queued_at);
        Notification::assertSentTo($order->user, InventoryReservationExpiredNotification::class, 1);
    }

    public function test_unexpired_paid_and_cash_on_delivery_reservations_are_preserved(): void
    {
        [$unexpired, $unexpiredProduct] = $this->reservedOrder(now()->addMinute());
        [$paid, $paidProduct] = $this->reservedOrder(now()->subMinute(), paymentStatus: 'paid');
        [$cash, $cashProduct] = $this->reservedOrder(null, method: 'cash_on_delivery', status: 'confirmed', paymentStatus: 'unpaid');

        $released = app(InventoryReservationService::class)->releaseExpired();

        $this->assertSame(0, $released);
        $this->assertSame(3, $unexpiredProduct->fresh()->stock);
        $this->assertSame(3, $paidProduct->fresh()->stock);
        $this->assertSame(3, $cashProduct->fresh()->stock);
        $this->assertSame('reserved', $unexpired->reservations()->firstOrFail()->status);
        $this->assertSame('reserved', $paid->reservations()->firstOrFail()->status);
        $this->assertSame('reserved', $cash->reservations()->firstOrFail()->status);
    }

    public function test_late_successful_payment_restores_inventory_and_confirms_order(): void
    {
        [$order, $product] = $this->reservedOrder(now()->subMinute());
        $payment = $this->payment($order);
        app(InventoryReservationService::class)->releaseExpired();

        app(PaymentSettlementService::class)->apply($payment, $this->successfulTransaction($payment));

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfilment_status' => 'unfulfilled',
            'cancellation_code' => null,
            'cancelled_at' => null,
        ]);
        $reservation = $order->reservations()->firstOrFail();
        $this->assertSame('reserved', $reservation->status);
        $this->assertNull($reservation->expires_at);
        $this->assertNull($reservation->released_at);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => 'cancelled',
            'to_status' => 'confirmed',
            'source' => 'payment',
        ]);
    }

    public function test_new_payment_cannot_start_after_the_reservation_deadline(): void
    {
        [$order] = $this->reservedOrder(now()->subMinute());
        Http::fake();

        $this->actingAs($order->user, 'api')->postJson('/api/v1/payments/initialize', [
            'order_id' => $order->id,
            'method' => 'card',
            'callback_url' => 'https://shop.limitra.test/payment/callback',
        ], ['Idempotency-Key' => 'expired-reservation-payment'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');

        Http::assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_late_payment_does_not_oversell_when_inventory_is_unavailable(): void
    {
        [$order, $product] = $this->reservedOrder(now()->subMinute());
        $payment = $this->payment($order);
        app(InventoryReservationService::class)->releaseExpired();
        $product->update(['stock' => 0]);
        Queue::fake();
        Notification::fake();

        app(PaymentSettlementService::class)->apply($payment, $this->successfulTransaction($payment));

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'paid',
            'cancellation_code' => InventoryReservationService::LATE_PAYMENT_CANCELLATION_CODE,
        ]);
        $this->assertSame('released', $order->reservations()->firstOrFail()->status);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => 'cancelled',
            'to_status' => 'cancelled',
            'source' => 'payment',
        ]);
        $this->assertDatabaseHas('refunds', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'return_request_id' => null,
            'source' => 'late_payment',
            'status' => 'initiating',
            'amount_minor' => 200000,
        ]);
        Queue::assertPushed(ProcessAutomaticRefund::class, 1);
        Notification::assertSentTo($order->user, AutomaticRefundInitiatedNotification::class, 1);

        app(PaymentSettlementService::class)->apply($payment, $this->successfulTransaction($payment));
        $this->assertDatabaseCount('refunds', 1);

        $refund = Refund::query()->firstOrFail();
        Http::fake([
            'https://api.paystack.co/refund' => Http::response(['status' => true, 'data' => [
                'id' => 900001,
                'transaction' => ['reference' => $payment->reference],
                'amount' => 200000,
                'currency' => 'NGN',
                'status' => 'processed',
            ]]),
        ]);

        app()->call([new ProcessAutomaticRefund($refund->id), 'handle']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/refund'
            && $request['transaction'] === $payment->reference
            && $request['amount'] === 200000);
        $this->assertSame('processed', $refund->fresh()->status);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'cancellation_code' => InventoryReservationService::LATE_PAYMENT_REFUNDED_CANCELLATION_CODE,
        ]);
        Notification::assertSentTo($order->user, AutomaticRefundProcessedNotification::class, 1);

        app(RefundSettlementService::class)->apply($refund, [
            'status' => 'pending',
            'transaction_reference' => $payment->reference,
            'amount' => 200000,
            'currency' => 'NGN',
        ]);
        $this->assertSame('processed', $refund->fresh()->status);
    }

    public function test_refund_attention_alerts_customer_and_staff_once(): void
    {
        [$order, $product] = $this->reservedOrder(now()->subMinute());
        $payment = $this->payment($order);
        app(InventoryReservationService::class)->releaseExpired();
        $product->update(['stock' => 0]);
        Queue::fake();
        Notification::fake();
        app(PaymentSettlementService::class)->apply($payment, $this->successfulTransaction($payment));
        $refund = Refund::query()->firstOrFail();
        $admin = $this->staff('admin');
        $staff = $this->staff('staff');

        $refund->update([
            'status' => 'needs_attention',
            'failure_message' => 'Provider requires a manual refund.',
        ]);
        app(RefundNotificationService::class)->queueAttention($refund);
        app(RefundNotificationService::class)->queueAttention($refund);

        Notification::assertSentTo($order->user, AutomaticRefundAttentionNotification::class, 1);
        Notification::assertSentTo($admin, AutomaticRefundStaffAlert::class, 1);
        Notification::assertSentTo($staff, AutomaticRefundStaffAlert::class, 1);
        $this->assertNotNull($refund->fresh()->attention_notification_queued_at);
    }

    private function reservedOrder(
        mixed $expiresAt,
        string $method = 'card',
        string $status = 'pending_payment',
        string $paymentStatus = 'pending',
    ): array {
        $user = User::query()->create([
            'username' => 'reservation-'.Str::lower(Str::random(8)),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);
        $category = Category::query()->create([
            'name' => 'Reservation '.Str::random(8),
            'slug' => 'reservation-'.Str::lower(Str::random(12)),
            'active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Reserved product',
            'slug' => 'reserved-product-'.Str::lower(Str::random(12)),
            'price' => 1000,
            'currency' => 'NGN',
            'stock' => 3,
            'status' => 'active',
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'number' => 'LMT-RES-'.Str::upper(Str::random(12)),
            'currency' => 'NGN',
            'subtotal' => 2000,
            'grand_total' => 2000,
            'total_amount' => 2000,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => $method,
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => [],
        ]);
        $order->reservations()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'reserved',
            'expires_at' => $expiresAt,
        ]);

        return [$order, $product];
    }

    private function payment(Order $order): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => 'LMT-LATE-'.Str::upper(Str::random(12)),
            'status' => 'pending',
            'currency' => 'NGN',
            'amount' => 2000,
            'amount_minor' => 200000,
            'customer_email' => $order->contact_email,
        ]);
    }

    private function staff(string $role): User
    {
        return User::query()->create([
            'username' => $role.'-'.Str::lower(Str::random(8)),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    private function successfulTransaction(Payment $payment): array
    {
        return [
            'id' => (string) random_int(100000, 999999),
            'status' => 'success',
            'reference' => $payment->reference,
            'amount' => $payment->amount_minor,
            'currency' => $payment->currency,
            'customer' => ['email' => $payment->customer_email],
        ];
    }
}
