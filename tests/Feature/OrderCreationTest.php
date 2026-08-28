<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Commerce\CheckoutQuote;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\DeliveryZone;
use App\Models\Order\Order;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_order_is_created_from_quote_with_snapshots_and_reserved_stock(): void
    {
        [$user, $cart, $product, $address] = $this->checkoutContext(quantity: 2);
        $quoteId = $this->quote($user, $cart, $address, 'card');

        $response = $this->actingAs($user, 'api')->postJson('/api/v1/orders', [
            'quote_id' => $quoteId,
            'payment_method' => 'card',
            'contact_email' => 'Buyer@Example.com',
            'notes' => 'Leave with reception.',
        ], ['Idempotency-Key' => 'create-order-001'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.fulfilment_status', 'unfulfilled')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.grand_total', '202500.00')
            ->assertJsonPath('data.items.0.name', 'Apex Phone')
            ->assertJsonPath('data.items.0.unit_price', '100000.00')
            ->assertJsonPath('data.shipping_address.line1', '14 Admiralty Way');

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertStringStartsWith('LMT-', $order->number);
        $this->assertSame('buyer@example.com', $order->contact_email);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'reserved',
        ]);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame('checked_out', $cart->fresh()->status);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertNotNull(CheckoutQuote::where('quote_id', $quoteId)->firstOrFail()->consumed_at);

        $product->update(['name' => 'Renamed Phone', 'price' => 150000]);
        $this->actingAs($user, 'api')->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'Apex Phone')
            ->assertJsonPath('data.items.0.unit_price', '100000.00');
    }

    public function test_same_idempotency_key_replays_once_and_rejects_conflicting_payloads(): void
    {
        [$user, $cart, $product, $address] = $this->checkoutContext();
        $quoteId = $this->quote($user, $cart, $address, 'cash_on_delivery');
        $payload = [
            'quote_id' => $quoteId,
            'payment_method' => 'cash_on_delivery',
            'contact_email' => $user->email,
        ];
        $headers = ['Idempotency-Key' => 'create-order-retry'];

        $first = $this->actingAs($user, 'api')->postJson('/api/v1/orders', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $second = $this->actingAs($user, 'api')->postJson('/api/v1/orders', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('message', 'Order already created.');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame(4, $product->fresh()->stock);

        $this->actingAs($user, 'api')->postJson('/api/v1/orders', $payload + ['notes' => 'Different request'], $headers)
            ->assertConflict();
    }

    public function test_changed_price_rolls_back_order_and_leaves_quote_and_cart_usable(): void
    {
        [$user, $cart, $product, $address] = $this->checkoutContext();
        $quoteId = $this->quote($user, $cart, $address, 'bank_transfer');
        $product->update(['price' => 120000]);

        $this->actingAs($user, 'api')->postJson('/api/v1/orders', [
            'quote_id' => $quoteId,
            'payment_method' => 'bank_transfer',
            'contact_email' => $user->email,
        ], ['Idempotency-Key' => 'create-order-stale'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_id');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame('active', $cart->fresh()->status);
        $this->assertNull(CheckoutQuote::where('quote_id', $quoteId)->firstOrFail()->consumed_at);
    }

    public function test_two_valid_quotes_cannot_oversell_the_same_inventory(): void
    {
        [$firstUser, $firstCart, $product, $firstAddress] = $this->checkoutContext(quantity: 3);
        $firstQuote = $this->quote($firstUser, $firstCart, $firstAddress, 'card');

        $secondUser = $this->user('second-buyer@example.com');
        $secondCart = Cart::activeForUser($secondUser->id);
        app(CartService::class)->add($secondCart, $product, null, [], 3);
        $secondAddress = $this->address($secondUser);
        $secondQuote = $this->quote($secondUser, $secondCart, $secondAddress, 'card');

        $this->actingAs($firstUser, 'api')->postJson('/api/v1/orders', [
            'quote_id' => $firstQuote,
            'payment_method' => 'card',
            'contact_email' => $firstUser->email,
        ], ['Idempotency-Key' => 'stock-order-first'])->assertCreated();

        $this->actingAs($secondUser, 'api')->postJson('/api/v1/orders', [
            'quote_id' => $secondQuote,
            'payment_method' => 'card',
            'contact_email' => $secondUser->email,
        ], ['Idempotency-Key' => 'stock-order-second'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_id');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('active', $secondCart->fresh()->status);
        $this->assertNull(CheckoutQuote::where('quote_id', $secondQuote)->firstOrFail()->consumed_at);
    }

    private function checkoutContext(int $quantity = 1): array
    {
        $user = $this->user();
        $category = Category::create(['name' => 'Phones', 'slug' => fake()->unique()->slug(), 'active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Apex Phone',
            'slug' => fake()->unique()->slug(),
            'sku' => 'APEX-001',
            'price' => 100000,
            'currency' => 'NGN',
            'stock' => 5,
            'status' => 'active',
        ]);
        $cart = Cart::activeForUser($user->id);
        app(CartService::class)->add($cart, $product, null, [], $quantity);
        $address = $this->address($user);
        $zone = DeliveryZone::firstOrCreate(
            ['name' => 'Lagos'],
            ['country' => 'NG', 'states' => ['Lagos'], 'priority' => 10],
        );
        $method = DeliveryMethod::firstOrCreate(
            ['code' => 'standard'],
            ['name' => 'Standard', 'type' => 'standard'],
        );
        $zone->methods()->syncWithoutDetaching([$method->id => [
            'fee' => 2500,
            'estimated_days_min' => 2,
            'estimated_days_max' => 4,
            'active' => true,
        ]]);

        return [$user, $cart, $product, $address];
    }

    private function quote(User $user, Cart $cart, Address $address, string $paymentMethod): string
    {
        return $this->actingAs($user, 'api')->postJson('/api/v1/checkout/quote', [
            'cart_id' => $cart->id,
            'address_id' => $address->id,
            'delivery_method' => 'standard',
            'payment_method' => $paymentMethod,
        ])->assertCreated()->json('data.quote_id');
    }

    private function user(string $email = 'buyer@example.com'): User
    {
        return User::create([
            'username' => (string) str($email)->before('@'),
            'email' => $email,
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }

    private function address(User $user): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'type' => 'delivery',
            'label' => 'Home',
            'recipient_name' => 'Lucy Limitra',
            'phone' => '+2348000000000',
            'line1' => '14 Admiralty Way',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'country' => 'NG',
            'is_default' => true,
        ]);
    }
}
