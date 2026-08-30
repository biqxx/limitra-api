<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\ReturnRequest;
use App\Models\Payment\Payment;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReturnRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'services.paystack.secret_key' => 'sk_test_refunds',
            'services.paystack.base_url' => 'https://api.paystack.co',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_delivered_order_return_is_always_created_as_pending_with_evidence(): void
    {
        Storage::fake('public');
        [$user, $order, $item] = $this->deliveredOrder();

        $response = $this->actingAs($user, 'api')->post("/api/v1/orders/{$order->id}/returns", [
            'status' => 'approved',
            'items' => [[
                'order_item_id' => $item->id,
                'quantity' => 1,
                'reason' => 'damaged',
                'notes' => 'The screen was cracked on arrival.',
            ]],
            'resolution' => 'refund',
            'notes' => 'Please review the attached evidence.',
            'images' => [UploadedFile::fake()->image('damage.jpg')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_total', '100000.00')
            ->assertJsonPath('data.approved_total', null)
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonCount(1, 'data.images');
        $returnRequest = ReturnRequest::firstOrFail();
        Storage::disk('public')->assertExists($returnRequest->images()->value('path'));
        $this->assertDatabaseHas('return_events', [
            'return_request_id' => $returnRequest->id,
            'to_status' => 'pending',
            'source' => 'customer',
        ]);
    }

    public function test_configured_return_window_and_resolutions_are_enforced(): void
    {
        [$user, $order, $item] = $this->deliveredOrder(deliveredDaysAgo: 8);
        $admin = $this->user('settings-admin@example.com', 'admin');
        app(BusinessSettingsService::class)->update([
            ['key' => 'returns.window_days', 'value' => 7],
            ['key' => 'returns.allowed_resolutions', 'value' => ['refund']],
        ], $admin);

        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'reason' => 'defective']],
            'resolution' => 'refund',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');

        [$recentUser, $recentOrder, $recentItem] = $this->deliveredOrder();
        $this->actingAs($recentUser, 'api')->postJson("/api/v1/orders/{$recentOrder->id}/returns", [
            'items' => [['order_item_id' => $recentItem->id, 'quantity' => 1, 'reason' => 'defective']],
            'resolution' => 'replacement',
        ])->assertUnprocessable()->assertJsonValidationErrors('resolution');
    }

    public function test_ownership_delivery_and_cumulative_quantity_are_enforced(): void
    {
        [$user, $order, $item] = $this->deliveredOrder();
        $other = $this->user('other-returner@example.com');

        $this->actingAs($other, 'api')->postJson("/api/v1/orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'reason' => 'defective']],
            'resolution' => 'refund',
        ])->assertNotFound();

        $payload = [
            'items' => [['order_item_id' => $item->id, 'quantity' => 2, 'reason' => 'defective']],
            'resolution' => 'refund',
        ];
        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/returns", $payload)->assertCreated();
        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'reason' => 'defective']],
            'resolution' => 'refund',
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        [$waitingUser, $waitingOrder, $waitingItem] = $this->deliveredOrder(fulfilmentStatus: 'processing');
        $this->actingAs($waitingUser, 'api')->postJson("/api/v1/orders/{$waitingOrder->id}/returns", [
            'items' => [['order_item_id' => $waitingItem->id, 'quantity' => 1, 'reason' => 'defective']],
            'resolution' => 'refund',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_customer_can_list_view_and_cancel_only_their_pending_returns(): void
    {
        [$user, $order, $item] = $this->deliveredOrder();
        $returnRequest = $this->submitReturn($user, $order, $item);
        $other = $this->user('return-viewer@example.com');

        $this->actingAs($user, 'api')->getJson('/api/v1/returns?status=pending')
            ->assertOk()->assertJsonPath('data.items.0.id', $returnRequest->id);
        $this->actingAs($other, 'api')->getJson("/api/v1/returns/{$returnRequest->id}")->assertForbidden();
        $this->actingAs($user, 'api')->postJson("/api/v1/returns/{$returnRequest->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($user, 'api')->postJson("/api/v1/returns/{$returnRequest->id}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors('return');
    }

    public function test_staff_approval_records_quantities_and_enforces_transitions(): void
    {
        [$user, $order, $item] = $this->deliveredOrder();
        $returnRequest = $this->submitReturn($user, $order, $item, quantity: 2);
        $returnItem = $returnRequest->items()->firstOrFail();
        $staff = $this->user('returns-staff@example.com', 'staff');

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/returns/{$returnRequest->id}", [
            'status' => 'approved',
            'notes' => 'One unit approved after evidence review.',
            'items' => [['id' => $returnItem->id, 'approved_quantity' => 1]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_total', '100000.00')
            ->assertJsonPath('data.items.0.approved_quantity', 1);
        $this->assertDatabaseHas('return_requests', ['id' => $returnRequest->id, 'approved_by' => $staff->id]);

        $this->actingAs($user, 'api')->postJson("/api/v1/returns/{$returnRequest->id}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors('return');
        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/returns/{$returnRequest->id}", [
            'status' => 'received',
            'notes' => 'Item received at the returns desk.',
        ])->assertOk()->assertJsonPath('data.status', 'received');
        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/returns/{$returnRequest->id}", [
            'status' => 'completed',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_only_admin_can_submit_idempotent_refund_and_webhook_finalizes_it(): void
    {
        [$user, $order, $item] = $this->deliveredOrder();
        $payment = $this->payment($order);
        $returnRequest = $this->submitReturn($user, $order, $item, quantity: 2);
        $returnItem = $returnRequest->items()->firstOrFail();
        $staff = $this->user('refund-staff@example.com', 'staff');
        $admin = $this->user('refund-admin@example.com', 'admin');

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/returns/{$returnRequest->id}", [
            'status' => 'approved',
            'items' => [['id' => $returnItem->id, 'approved_quantity' => 1]],
        ])->assertOk();
        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/returns/{$returnRequest->id}", [
            'status' => 'received',
        ])->assertOk();

        $refundPayload = ['amount' => '100000.00', 'method' => 'original_payment', 'reason' => 'Approved damaged item return.'];
        $this->actingAs($staff, 'api')->postJson("/api/v1/admin/returns/{$returnRequest->id}/refund", $refundPayload, [
            'Idempotency-Key' => 'return-refund-001',
        ])->assertForbidden();

        Http::fake([
            'https://api.paystack.co/refund' => Http::response(['status' => true, 'data' => [
                'id' => 3018284,
                'transaction' => ['reference' => $payment->reference],
                'amount' => 10000000,
                'currency' => 'NGN',
                'status' => 'pending',
            ]]),
        ]);
        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/returns/{$returnRequest->id}/refund", $refundPayload, [
            'Idempotency-Key' => 'return-refund-001',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/returns/{$returnRequest->id}/refund", $refundPayload, [
            'Idempotency-Key' => 'return-refund-001',
        ])->assertOk()->assertJsonPath('data.status', 'pending');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/refund'
            && $request['transaction'] === $payment->reference
            && $request['amount'] === 10000000);

        $webhook = json_encode(['event' => 'refund.processed', 'data' => [
            'status' => 'processed',
            'transaction_reference' => $payment->reference,
            'refund_reference' => 'RFD-WEBHOOK-001',
            'amount' => '10000000',
            'currency' => 'NGN',
            'refunded_at' => now()->toIso8601String(),
        ]], JSON_THROW_ON_ERROR);
        $this->postRawWebhook($webhook, hash_hmac('sha512', $webhook, 'sk_test_refunds'))->assertOk();

        $this->assertDatabaseHas('refunds', ['return_request_id' => $returnRequest->id, 'status' => 'processed']);
        $this->assertSame('completed', $returnRequest->fresh()->status);
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
    }

    private function user(string $email, string $role = 'user'): User
    {
        return User::create([
            'username' => strtok($email, '@').random_int(100, 999),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function deliveredOrder(int $deliveredDaysAgo = 1, string $fulfilmentStatus = 'delivered'): array
    {
        $user = $this->user('return-buyer-'.str()->random(6).'@example.com');
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones-'.str()->random(6), 'status' => 'active']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Apex Return Phone',
            'slug' => 'apex-return-'.str()->random(6),
            'sku' => 'RET-'.str()->upper(str()->random(6)),
            'price' => 100000,
            'stock' => 10,
            'status' => 'active',
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'number' => 'LMT-RET-'.str()->upper(str()->random(8)),
            'currency' => 'NGN',
            'subtotal' => 200000,
            'grand_total' => 202500,
            'total_amount' => 202500,
            'status' => $fulfilmentStatus === 'delivered' ? 'delivered' : 'processing',
            'payment_status' => 'paid',
            'fulfilment_status' => $fulfilmentStatus,
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '14 Admiralty Way', 'country' => 'NG'],
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => 100000,
            'price_at_purchase' => 100000,
            'line_total' => 200000,
        ]);
        if ($fulfilmentStatus === 'delivered') {
            $order->shipment()->create([
                'courier' => 'DHL',
                'tracking_number' => 'DHL-'.str()->upper(str()->random(10)),
                'status' => 'delivered',
                'shipped_at' => now()->subDays($deliveredDaysAgo + 2),
                'delivered_at' => now()->subDays($deliveredDaysAgo),
            ]);
        }

        return [$user, $order, $item];
    }

    private function submitReturn(User $user, Order $order, OrderItem $item, int $quantity = 1): ReturnRequest
    {
        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/returns", [
            'items' => [['order_item_id' => $item->id, 'quantity' => $quantity, 'reason' => 'damaged']],
            'resolution' => 'refund',
        ])->assertCreated();

        return ReturnRequest::latest('id')->firstOrFail();
    }

    private function payment(Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => 'LMT-PAY-RET-'.str()->upper(str()->random(10)),
            'status' => 'succeeded',
            'currency' => 'NGN',
            'amount' => 202500,
            'amount_minor' => 20250000,
            'customer_email' => $order->contact_email,
            'paid_at' => now()->subDays(4),
            'verified_at' => now()->subDays(4),
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
