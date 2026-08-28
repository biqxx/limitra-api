<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Cart\Cart;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartCatalogAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_variant_lines_merge_and_return_current_cart_totals(): void
    {
        $user = $this->user('cart-user@example.com');
        [$product, $variant] = $this->variantProduct();

        $this->actingAs($user, 'api')->postJson('/api/v1/cart/add', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'selected_options' => ['storage' => '256GB', 'color' => 'Black'],
            'quantity' => 2,
        ])->assertOk()
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.subtotal', '900000.00')
            ->assertJsonPath('data.items.0.unit_price', '450000.00')
            ->assertJsonPath('data.items.0.available', true);

        $this->actingAs($user, 'api')->postJson('/api/v1/cart/add', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3);

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_cart_enforces_variant_selection_and_stock(): void
    {
        $user = $this->user('stock-user@example.com');
        [$product, $variant] = $this->variantProduct();

        $this->actingAs($user, 'api')->postJson('/api/v1/cart/add', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('variant_id');

        $this->actingAs($user, 'api')->postJson('/api/v1/cart/add', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 6,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_cart_read_flags_price_changes_and_unavailability(): void
    {
        $user = $this->user('availability-user@example.com');
        [$product, $variant] = $this->variantProduct();

        $this->actingAs($user, 'api')->postJson('/api/v1/cart/add', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertOk();

        $variant->update(['price' => 475000, 'stock' => 1]);

        $this->actingAs($user, 'api')->getJson('/api/v1/cart/active')
            ->assertOk()
            ->assertJsonPath('data.items.0.price_changed', true)
            ->assertJsonPath('data.items.0.available', false)
            ->assertJsonPath('data.items.0.available_stock', 1)
            ->assertJsonPath('data.subtotal', '950000.00');
    }

    public function test_cart_item_endpoints_reject_non_owners(): void
    {
        $owner = $this->user('owner@example.com');
        $intruder = $this->user('intruder@example.com');
        [$product] = $this->variantProduct(withVariant: false);
        $cart = Cart::activeForUser($owner->id);
        $item = $cart->items()->create([
            'product_id' => $product->id,
            'line_key' => hash('sha256', 'owner-line'),
            'quantity' => 1,
            'unit_price_at_addition' => $product->price,
        ]);

        $this->actingAs($intruder, 'api')->getJson("/api/v1/cart-items/{$item->id}")->assertForbidden();
        $this->actingAs($intruder, 'api')->patchJson("/api/v1/cart-items/{$item->id}", ['quantity' => 2])->assertForbidden();
        $this->actingAs($intruder, 'api')->deleteJson("/api/v1/cart-items/{$item->id}")->assertForbidden();
        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
    }

    public function test_favorites_support_variants_pagination_and_ownership(): void
    {
        $owner = $this->user('favorite-owner@example.com');
        $intruder = $this->user('favorite-intruder@example.com');
        [$product, $variant] = $this->variantProduct();

        $response = $this->actingAs($owner, 'api')->postJson('/api/v1/favorites', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'selected_options' => ['color' => 'Black', 'storage' => '256GB'],
        ])->assertCreated()->assertJsonPath('data.variant_id', $variant->id);

        $favoriteId = $response->json('data.id');
        $this->actingAs($owner, 'api')->getJson('/api/v1/favorites?per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($intruder, 'api')->deleteJson("/api/v1/favorites/{$favoriteId}")->assertForbidden();
    }

    private function user(string $email): User
    {
        return User::create([
            'username' => (string) str($email)->before('@')->replace('.', '-'),
            'email' => $email,
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }

    private function variantProduct(bool $withVariant = true): array
    {
        $category = Category::create(['name' => fake()->unique()->word(), 'slug' => fake()->unique()->slug(), 'active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Apex Phone',
            'slug' => fake()->unique()->slug(),
            'price' => 400000,
            'currency' => 'NGN',
            'stock' => 10,
            'status' => 'active',
        ]);

        if (! $withVariant) {
            return [$product, null];
        }

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => fake()->unique()->bothify('SKU-####'),
            'attributes' => ['color' => 'Black', 'storage' => '256GB'],
            'price' => 450000,
            'stock' => 5,
            'status' => 'active',
        ]);

        return [$product, $variant];
    }
}
