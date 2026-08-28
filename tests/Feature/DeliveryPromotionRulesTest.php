<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Cart\Cart;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\DeliveryZone;
use App\Models\Commerce\PickupLocation;
use App\Models\Commerce\Promotion;
use App\Models\Commerce\PromotionRedemption;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryPromotionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_delivery_options_match_zone_and_apply_free_shipping_threshold(): void
    {
        $user = $this->user();
        [$product] = $this->product(price: 100000);
        $cart = Cart::activeForUser($user->id);
        app(CartService::class)->add($cart, $product, null, [], 2);
        $zone = DeliveryZone::create(['name' => 'Lagos', 'country' => 'NG', 'states' => ['Lagos'], 'priority' => 10]);
        $method = DeliveryMethod::create(['code' => 'standard', 'name' => 'Standard', 'type' => 'standard']);
        $zone->methods()->attach($method, [
            'fee' => 2500, 'free_shipping_threshold' => 150000,
            'estimated_days_min' => 2, 'estimated_days_max' => 4, 'active' => true,
        ]);

        $this->actingAs($user, 'api')->getJson("/api/v1/delivery/options?cart_id={$cart->id}&country=NG&state=Lagos&city=Ikeja")
            ->assertOk()
            ->assertJsonPath('data.0.code', 'standard')
            ->assertJsonPath('data.0.fee', '0.00')
            ->assertJsonPath('data.0.estimated_days.min', 2);
    }

    public function test_delivery_quote_rejects_a_foreign_cart(): void
    {
        $owner = $this->user('owner@example.com');
        $other = $this->user('other@example.com');
        $cart = Cart::activeForUser($owner->id);

        $this->actingAs($other, 'api')->getJson("/api/v1/delivery/options?cart_id={$cart->id}&country=NG&state=Lagos")
            ->assertForbidden();
    }

    public function test_promotion_validation_honours_scope_caps_and_customer_limits(): void
    {
        $user = $this->user();
        [$product, $category] = $this->product(price: 100000);
        $cart = Cart::activeForUser($user->id);
        app(CartService::class)->add($cart, $product, null, [], 2);
        $promotion = Promotion::create([
            'code' => 'WELCOME10', 'name' => 'Welcome', 'type' => 'percentage', 'value' => 10,
            'maximum_discount' => 15000, 'minimum_spend' => 100000, 'per_customer_limit' => 1,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'active' => true,
        ]);
        $promotion->categories()->attach($category);

        $this->actingAs($user, 'api')->postJson('/api/v1/promotions/validate', [
            'code' => 'welcome10', 'cart_id' => $cart->id,
        ])->assertOk()
            ->assertJsonPath('data.eligible_subtotal', '200000.00')
            ->assertJsonPath('data.discount_amount', '15000.00');

        PromotionRedemption::create([
            'promotion_id' => $promotion->id, 'user_id' => $user->id, 'discount_amount' => 15000,
        ]);
        $this->actingAs($user, 'api')->postJson('/api/v1/promotions/validate', [
            'code' => 'WELCOME10', 'cart_id' => $cart->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_pickup_locations_are_filtered_without_exposing_inactive_rows(): void
    {
        PickupLocation::create(['code' => 'IKEJA', 'name' => 'Ikeja Hub', 'line1' => '1 Allen Avenue', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'NG']);
        PickupLocation::create(['code' => 'OLD', 'name' => 'Old Hub', 'line1' => '2 Allen Avenue', 'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'NG', 'active' => false]);

        $this->getJson('/api/v1/pickup-locations?state=Lagos&city=Ikeja')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'IKEJA');
    }

    public function test_only_admin_can_create_commerce_rules(): void
    {
        $user = $this->user();
        $admin = $this->user('admin@example.com', 'admin');
        $payload = ['code' => 'express', 'name' => 'Express', 'type' => 'express'];

        $this->actingAs($user, 'api')->postJson('/api/v1/admin/delivery-methods', $payload)->assertForbidden();
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/delivery-methods', $payload)
            ->assertCreated()->assertJsonPath('data.code', 'express');
    }

    private function user(string $email = 'customer@example.com', string $role = 'user'): User
    {
        return User::create([
            'username' => (string) str($email)->before('@'), 'email' => $email,
            'password' => 'password', 'role' => $role, 'email_verified_at' => now(),
        ]);
    }

    private function product(float $price): array
    {
        $category = Category::create(['name' => fake()->unique()->word(), 'slug' => fake()->unique()->slug(), 'active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => fake()->words(2, true), 'slug' => fake()->unique()->slug(),
            'price' => $price, 'currency' => 'NGN', 'stock' => 10, 'status' => 'active',
        ]);

        return [$product, $category];
    }
}
