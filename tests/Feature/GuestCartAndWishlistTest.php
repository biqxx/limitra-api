<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Cart\Cart;
use App\Models\Cart\Favorite;
use App\Models\Cart\WishlistShare;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestCartAndWishlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_guest_cart_uses_an_opaque_token_and_is_isolated(): void
    {
        $product = $this->product();
        $response = $this->getJson('/api/v1/cart/active')->assertOk();
        $token = $response->headers->get('X-Cart-Token');

        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('carts', [
            'user_id' => null,
            'guest_token_hash' => hash('sha256', $token),
            'status' => 'active',
        ]);
        $this->assertStringNotContainsString($token, $response->getContent());

        $added = $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/cart/add', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertOk()->assertJsonPath('data.item_count', 2);

        $itemId = $added->json('data.items.0.id');
        $this->withHeader('X-Cart-Token', str_repeat('x', 64))
            ->patchJson("/api/v1/cart-items/{$itemId}", ['quantity' => 1])
            ->assertForbidden();
    }

    public function test_merge_caps_stock_and_invalidates_the_guest_token(): void
    {
        $product = $this->product(stock: 5);
        $guest = $this->getJson('/api/v1/cart/active');
        $token = $guest->headers->get('X-Cart-Token');
        $this->withHeader('X-Cart-Token', $token)->postJson('/api/v1/cart/add', [
            'product_id' => $product->id,
            'quantity' => 4,
        ])->assertOk();

        $user = $this->user('merge@example.com');
        $userCart = Cart::activeForUser($user->id);
        app(CartService::class)->add($userCart, $product, null, [], 3);

        $this->actingAs($user, 'api')->postJson('/api/v1/cart/merge', [
            'anonymous_cart_token' => $token,
        ])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 5);

        $this->assertNull(Cart::activeForGuestToken($token));
        $this->actingAs($user, 'api')->postJson('/api/v1/cart/merge', [
            'anonymous_cart_token' => $token,
        ])->assertNotFound();
    }

    public function test_shared_wishlist_is_public_expiring_and_reports_availability(): void
    {
        $user = $this->user('share@example.com');
        $product = $this->product();
        Favorite::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'line_key' => app(CartService::class)->lineKey($product->id, null, []),
        ]);

        $share = $this->actingAs($user, 'api')->postJson('/api/v1/favorites/share', [
            'expires_in_days' => 5,
        ])->assertCreated();
        $token = $share->json('data.token');

        $this->assertDatabaseMissing('wishlist_shares', ['token_hash' => $token]);
        $this->assertDatabaseHas('wishlist_shares', ['token_hash' => hash('sha256', $token)]);
        $this->getJson("/api/v1/shared-wishlists/{$token}")
            ->assertOk()
            ->assertJsonPath('data.items.0.available', true)
            ->assertJsonMissingPath('data.items.0.user_id');

        WishlistShare::query()->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/shared-wishlists/{$token}")->assertNotFound();
    }

    private function user(string $email): User
    {
        return User::create([
            'username' => (string) str($email)->before('@'),
            'email' => $email,
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }

    private function product(int $stock = 10): Product
    {
        $category = Category::create(['name' => fake()->unique()->word(), 'slug' => fake()->unique()->slug(), 'active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(),
            'price' => 10000,
            'currency' => 'NGN',
            'stock' => $stock,
            'status' => 'active',
        ]);
    }
}
