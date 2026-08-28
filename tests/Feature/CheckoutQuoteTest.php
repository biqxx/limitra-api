<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Commerce\CheckoutQuote;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\DeliveryZone;
use App\Models\Commerce\Promotion;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_quote_reprices_and_persists_authoritative_snapshots(): void
    {
        [$user, $cart, $product, $address] = $this->checkoutContext();
        $promotion = Promotion::create([
            'code' => 'SAVE10', 'name' => 'Save ten', 'type' => 'percentage', 'value' => 10,
            'minimum_spend' => 100000, 'active' => true,
        ]);
        $promotion->products()->attach($product);
        $product->update(['price' => 120000]);

        $response = $this->actingAs($user, 'api')->postJson('/api/v1/checkout/quote', [
            'cart_id' => $cart->id,
            'address_id' => $address->id,
            'delivery_method' => 'standard',
            'payment_method' => 'card',
            'promotion_code' => 'SAVE10',
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', '240000.00')
            ->assertJsonPath('data.discounts.0.amount', '24000.00')
            ->assertJsonPath('data.shipping.fee', '2500.00')
            ->assertJsonPath('data.grand_total', '218500.00')
            ->assertJsonPath('data.lines.0.unit_price', '120000.00')
            ->assertJsonPath('data.warnings.0.type', 'price_changed');

        $quoteId = $response->json('data.quote_id');
        $this->assertNotEmpty($quoteId);
        $this->assertDatabaseHas('checkout_quotes', [
            'quote_id' => $quoteId, 'user_id' => $user->id, 'subtotal' => 240000, 'grand_total' => 218500,
        ]);
        $this->assertDatabaseHas('checkout_quote_items', [
            'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 2, 'unit_price' => 120000,
        ]);
        $quote = CheckoutQuote::where('quote_id', $quoteId)->firstOrFail();
        $this->assertTrue($quote->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));
        $this->assertNull($quote->consumed_at);
    }

    public function test_free_shipping_promotion_zeroes_delivery_without_changing_subtotal(): void
    {
        [$user, $cart, $product, $address] = $this->checkoutContext();
        $promotion = Promotion::create([
            'code' => 'SHIPFREE', 'name' => 'Free shipping', 'type' => 'free_shipping', 'value' => 0,
            'minimum_spend' => 100000, 'active' => true,
        ]);

        $this->actingAs($user, 'api')->postJson('/api/v1/checkout/quote', [
            'cart_id' => $cart->id, 'address_id' => $address->id, 'delivery_method' => 'standard',
            'payment_method' => 'bank_transfer', 'promotion_code' => 'SHIPFREE',
        ])->assertCreated()
            ->assertJsonPath('data.shipping.fee', '0.00')
            ->assertJsonPath('data.discounts.0.free_shipping', true)
            ->assertJsonPath('data.grand_total', '200000.00');
    }

    public function test_quote_rejects_foreign_addresses_and_insufficient_stock(): void
    {
        [$user, $cart, $product] = $this->checkoutContext();
        $other = $this->user('other@example.com');
        $foreignAddress = $this->address($other);

        $this->actingAs($user, 'api')->postJson('/api/v1/checkout/quote', [
            'cart_id' => $cart->id, 'address_id' => $foreignAddress->id, 'delivery_method' => 'standard',
            'payment_method' => 'card',
        ])->assertNotFound();

        $product->update(['stock' => 1]);
        $ownAddress = $this->address($user);
        $this->actingAs($user, 'api')->postJson('/api/v1/checkout/quote', [
            'cart_id' => $cart->id, 'address_id' => $ownAddress->id, 'delivery_method' => 'standard',
            'payment_method' => 'card',
        ])->assertUnprocessable()->assertJsonValidationErrors('cart_id');
    }

    public function test_wallet_credit_is_explicitly_unavailable_until_ledger_exists(): void
    {
        [$user, $cart, , $address] = $this->checkoutContext();

        $this->actingAs($user, 'api')->postJson('/api/v1/checkout/quote', [
            'cart_id' => $cart->id, 'address_id' => $address->id, 'delivery_method' => 'standard',
            'payment_method' => 'card', 'use_wallet_credit' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('use_wallet_credit');
    }

    private function checkoutContext(): array
    {
        $user = $this->user();
        $category = Category::create(['name' => 'Phones', 'slug' => fake()->unique()->slug(), 'active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Apex Phone', 'slug' => fake()->unique()->slug(),
            'price' => 100000, 'currency' => 'NGN', 'stock' => 5, 'status' => 'active',
        ]);
        $cart = Cart::activeForUser($user->id);
        app(CartService::class)->add($cart, $product, null, [], 2);
        $address = $this->address($user);
        $zone = DeliveryZone::firstOrCreate(['name' => 'Lagos'], ['country' => 'NG', 'states' => ['Lagos'], 'priority' => 10]);
        $method = DeliveryMethod::firstOrCreate(['code' => 'standard'], ['name' => 'Standard', 'type' => 'standard']);
        $zone->methods()->syncWithoutDetaching([$method->id => [
            'fee' => 2500, 'estimated_days_min' => 2, 'estimated_days_max' => 4, 'active' => true,
        ]]);

        return [$user, $cart, $product, $address];
    }

    private function user(string $email = 'checkout@example.com'): User
    {
        return User::create([
            'username' => (string) str($email)->before('@'), 'email' => $email,
            'password' => 'password', 'role' => 'user', 'email_verified_at' => now(),
        ]);
    }

    private function address(User $user): Address
    {
        return Address::create([
            'user_id' => $user->id, 'type' => 'delivery', 'label' => 'Home', 'recipient_name' => 'Lucy Limitra',
            'phone' => '+2348000000000', 'line1' => '14 Admiralty Way', 'city' => 'Ikeja', 'state' => 'Lagos',
            'country' => 'NG', 'is_default' => true,
        ]);
    }
}
