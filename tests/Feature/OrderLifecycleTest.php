<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Address\Address;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\DeliveryZone;
use App\Models\Commerce\Promotion;
use App\Models\Commerce\PromotionRedemption;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Payment\Payment;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_cancellation_releases_inventory_and_promotion_exactly_once(): void
    {
        [$user, $order, $product] = $this->order(status: 'pending_payment', paymentStatus: 'failed');
        $order->reservations()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'reserved',
        ]);
        $promotion = Promotion::create([
            'code' => 'CANCEL10',
            'name' => 'Cancellation test',
            'type' => 'fixed',
            'value' => 1000,
            'active' => true,
        ]);
        PromotionRedemption::create([
            'promotion_id' => $promotion->id,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'discount_amount' => 1000,
        ]);

        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/cancel", [
            'reason' => 'Ordered by mistake',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.fulfilment_status', 'cancelled');

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'released',
        ]);
        $this->assertDatabaseMissing('promotion_redemptions', ['order_id' => $order->id]);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => 'pending_payment',
            'to_status' => 'cancelled',
            'source' => 'customer',
        ]);

        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/cancel", [
            'reason' => 'Repeated request',
        ])->assertOk();
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertDatabaseCount('order_status_events', 1);
    }

    public function test_active_payments_and_non_owners_cannot_cancel_an_order(): void
    {
        [$user, $order] = $this->order();
        $this->payment($order, 'pending');

        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/cancel", [
            'reason' => 'Changed my mind',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');

        $other = $this->user('other@example.com');
        $this->actingAs($other, 'api')->postJson("/api/v1/orders/{$order->id}/cancel", [
            'reason' => 'Not my order',
        ])->assertForbidden();
        $this->assertNotSame('cancelled', $order->fresh()->status);
    }

    public function test_delivery_address_can_change_only_when_method_and_fee_remain_valid(): void
    {
        [$user, $order] = $this->order(status: 'confirmed', method: 'cash_on_delivery', paymentStatus: 'unpaid');
        $this->deliveryRules();
        $address = $this->address($user, '22 New Lagos Road');

        $this->actingAs($user, 'api')->patchJson("/api/v1/orders/{$order->id}/delivery-address", [
            'address_id' => $address->id,
        ])->assertOk()
            ->assertJsonPath('data.shipping_address.line1', '22 New Lagos Road');
        $this->assertSame($address->id, $order->fresh()->shipping_address_id);

        $outsideZone = $this->address($user, '5 Abuja Road', state: 'Abuja', city: 'Abuja');
        $this->actingAs($user, 'api')->patchJson("/api/v1/orders/{$order->id}/delivery-address", [
            'address_id' => $outsideZone->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('address_id');
    }

    public function test_buy_again_uses_current_catalog_price_and_selected_items(): void
    {
        [$user, $order, $product, $item] = $this->order();
        $product->update(['price' => 125000, 'stock' => 10]);

        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/buy-again", [
            'item_ids' => [$item->id],
        ])->assertOk()
            ->assertJsonPath('data.items.0.product.id', $product->id)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '125000.00');

        $this->actingAs($user, 'api')->postJson("/api/v1/orders/{$order->id}/buy-again", [
            'item_ids' => [999999],
        ])->assertUnprocessable()->assertJsonValidationErrors('item_ids');
    }

    public function test_order_history_invoice_json_and_pdf_use_historical_snapshots(): void
    {
        [$user, $order, $product] = $this->order(status: 'delivered', paymentStatus: 'paid');
        $product->update(['name' => 'Renamed Product', 'price' => 999999]);

        $this->actingAs($user, 'api')->getJson('/api/v1/orders?q=Apex')
            ->assertOk()
            ->assertJsonPath('data.items.0.number', $order->number)
            ->assertJsonPath('data.items.0.items.0.name', 'Apex Phone')
            ->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($user, 'api')->getJson("/api/v1/orders/{$order->id}/invoice")
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'INV-'.$order->number)
            ->assertJsonPath('data.items.0.name', 'Apex Phone')
            ->assertJsonPath('data.totals.grand_total', '202500.00');

        $pdf = $this->actingAs($user, 'api')->get("/api/v1/orders/{$order->id}/invoice?format=pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf->getContent());
        $this->assertStringContainsString('INV-'.$order->number, $pdf->headers->get('content-disposition'));
    }

    public function test_admin_shipping_and_forward_transitions_build_customer_tracking(): void
    {
        [$customer, $order, $product] = $this->order(
            status: 'confirmed',
            method: 'cash_on_delivery',
            paymentStatus: 'unpaid',
        );
        $order->reservations()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'reserved',
        ]);
        $admin = $this->user('admin@example.com', 'admin');

        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/orders/{$order->id}/ship", [
            'courier' => 'DHL',
            'tracking_number' => 'DHL-LMT-001',
            'location' => 'Lagos Hub',
        ])->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.tracking.tracking_number', 'DHL-LMT-001');
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'committed',
        ]);

        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'in_transit',
            'note' => 'Departed Lagos hub.',
            'location' => 'Lagos',
        ])->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'delivered',
            'note' => 'Delivered to recipient.',
            'location' => 'Ikeja',
        ])->assertOk()->assertJsonPath('data.status', 'delivered');

        $this->actingAs($customer, 'api')->getJson("/api/v1/orders/{$order->id}/tracking")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.events.0.status', 'delivered')
            ->assertJsonCount(3, 'data.events');
        $this->assertDatabaseCount('order_status_events', 3);

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/orders/{$order->id}")
            ->assertStatus(405);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    private function order(
        string $status = 'pending_payment',
        string $method = 'card',
        string $paymentStatus = 'pending',
    ): array {
        $user = $this->user();
        $category = Category::create([
            'name' => 'Phones',
            'slug' => fake()->unique()->slug(),
            'active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Apex Phone',
            'slug' => fake()->unique()->slug(),
            'sku' => 'APEX-001',
            'price' => 100000,
            'currency' => 'NGN',
            'stock' => 3,
            'status' => 'active',
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'number' => 'LMT-LIFE-'.fake()->unique()->numberBetween(1000, 9999),
            'currency' => 'NGN',
            'subtotal' => 200000,
            'discount_total' => 0,
            'credit_total' => 0,
            'shipping_total' => 2500,
            'grand_total' => 202500,
            'total_amount' => 202500,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfilment_status' => in_array($status, ['pending_payment', 'confirmed'], true) ? 'unfulfilled' : $status,
            'payment_method' => $method,
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => [
                'recipient_name' => 'Lucy Limitra',
                'line1' => '14 Admiralty Way',
                'city' => 'Ikeja',
                'state' => 'Lagos',
                'country' => 'NG',
            ],
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Apex Phone',
            'sku' => 'APEX-001',
            'selected_options' => [],
            'quantity' => 2,
            'unit_price' => 100000,
            'price_at_purchase' => 100000,
            'line_total' => 200000,
        ]);

        return [$user, $order, $product, $item];
    }

    private function payment(Order $order, string $status): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => 'LMT-PAY-LIFE-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => $status,
            'currency' => 'NGN',
            'amount' => 202500,
            'amount_minor' => 20250000,
            'customer_email' => 'buyer@example.com',
        ]);
    }

    private function user(string $email = 'buyer@example.com', string $role = 'user'): User
    {
        return User::create([
            'username' => (string) str($email)->before('@'),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function address(
        User $user,
        string $line1,
        string $state = 'Lagos',
        string $city = 'Ikeja',
    ): Address {
        return Address::create([
            'user_id' => $user->id,
            'type' => 'delivery',
            'label' => 'Home',
            'recipient_name' => 'Lucy Limitra',
            'phone' => '+2348000000000',
            'line1' => $line1,
            'city' => $city,
            'state' => $state,
            'country' => 'NG',
        ]);
    }

    private function deliveryRules(): void
    {
        $zone = DeliveryZone::create([
            'name' => 'Lagos',
            'country' => 'NG',
            'states' => ['Lagos'],
            'priority' => 10,
        ]);
        $method = DeliveryMethod::create([
            'code' => 'standard',
            'name' => 'Standard',
            'type' => 'standard',
        ]);
        $zone->methods()->attach($method->id, [
            'fee' => 2500,
            'estimated_days_min' => 2,
            'estimated_days_max' => 4,
            'active' => true,
        ]);
    }
}
