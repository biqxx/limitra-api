<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_public_listing_contains_only_published_reviews_and_rating_summary(): void
    {
        $product = $this->product();
        $fiveStar = $this->review($product, $this->user('five@example.com'), 5, 'published');
        $fourStar = $this->review($product, $this->user('four@example.com'), 4, 'published');
        $this->review($product, $this->user('pending@example.com'), 1, 'pending');
        $product->update(['average_rating' => 4.5, 'review_count' => 2]);

        $this->getJson("/api/v1/products/{$product->id}/reviews?sort=highest_rating")
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $fiveStar->id)
            ->assertJsonPath('data.items.1.id', $fourStar->id)
            ->assertJsonPath('data.summary.average_rating', '4.50')
            ->assertJsonPath('data.summary.review_count', 2)
            ->assertJsonPath('data.summary.rating_breakdown.5', 1)
            ->assertJsonPath('data.summary.rating_breakdown.1', 0)
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson("/api/v1/products/{$product->id}/reviews?rating=4")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $fourStar->id);
    }

    public function test_customer_can_submit_one_verified_review_for_a_delivered_order_item(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $user = $this->user('buyer@example.com');
        $item = $this->orderItem($product, $user, 'delivered');

        $response = $this->actingAs($user, 'api')->post("/api/v1/products/{$product->id}/reviews", [
            'order_item_id' => $item->id,
            'rating' => 5,
            'title' => 'Excellent phone',
            'body' => 'The product arrived exactly as described.',
            'images' => [UploadedFile::fake()->image('delivery.png')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.verified_purchase', true)
            ->assertJsonPath('data.order_item_id', $item->id)
            ->assertJsonCount(1, 'data.images');
        $review = Review::firstOrFail();
        Storage::disk('public')->assertExists($review->images()->value('path'));
        $this->assertSame(0, $product->fresh()->review_count);

        $this->actingAs($user, 'api')->postJson("/api/v1/products/{$product->id}/reviews", [
            'order_item_id' => $item->id,
            'rating' => 4,
            'body' => 'A second review should not be accepted.',
        ])->assertUnprocessable()->assertJsonValidationErrors('order_item_id');
    }

    public function test_undelivered_items_and_items_owned_by_another_customer_cannot_be_reviewed(): void
    {
        $product = $this->product();
        $owner = $this->user('owner@example.com');
        $other = $this->user('other@example.com');
        $item = $this->orderItem($product, $owner, 'processing');

        $payload = ['order_item_id' => $item->id, 'rating' => 3, 'body' => 'Not delivered yet.'];
        $this->actingAs($owner, 'api')->postJson("/api/v1/products/{$product->id}/reviews", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('order_item_id');
        $this->actingAs($other, 'api')->postJson("/api/v1/products/{$product->id}/reviews", $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_staff_moderation_controls_product_rating_aggregates(): void
    {
        $product = $this->product();
        $owner = $this->user('reviewer@example.com');
        $review = $this->review($product, $owner, 4, 'pending');
        $staff = $this->user('staff@example.com', 'staff');

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/reviews/{$review->id}/moderate", [
            'status' => 'published',
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame(1, $product->fresh()->review_count);
        $this->assertSame('4.00', $product->fresh()->average_rating);

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/reviews/{$review->id}/moderate", [
            'status' => 'rejected',
            'reason' => 'Contains prohibited personal information.',
        ])->assertOk()->assertJsonPath('data.moderation_reason', 'Contains prohibited personal information.');
        $this->assertSame(0, $product->fresh()->review_count);
        $this->assertSame('0.00', $product->fresh()->average_rating);
    }

    public function test_owner_edits_are_remoderated_and_helpful_votes_are_idempotent(): void
    {
        $product = $this->product();
        $owner = $this->user('author@example.com');
        $other = $this->user('voter@example.com');
        $review = $this->review($product, $owner, 5, 'published');
        $product->update(['average_rating' => 5, 'review_count' => 1]);

        $this->actingAs($other, 'api')->postJson("/api/v1/reviews/{$review->id}/helpful")
            ->assertOk()->assertJsonPath('data.helpful_count', 1);
        $this->actingAs($other, 'api')->postJson("/api/v1/reviews/{$review->id}/helpful")
            ->assertOk()->assertJsonPath('data.helpful_count', 1);
        $this->assertDatabaseCount('review_helpful_votes', 1);

        $this->actingAs($other, 'api')->patchJson("/api/v1/reviews/{$review->id}", ['rating' => 2])
            ->assertForbidden();
        $this->actingAs($owner, 'api')->patchJson("/api/v1/reviews/{$review->id}", [
            'rating' => 3,
            'body' => 'Updated after using the product for longer.',
        ])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(0, $product->fresh()->review_count);

        $admin = $this->user('admin@example.com', 'admin');
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/reviews/{$review->id}/moderate", [
            'status' => 'published',
        ])->assertOk();
        $this->assertSame('3.00', $product->fresh()->average_rating);

        $this->actingAs($owner, 'api')->deleteJson("/api/v1/reviews/{$review->id}")->assertNoContent();
        $this->assertSoftDeleted('reviews', ['id' => $review->id]);
        $this->assertSame(0, $product->fresh()->review_count);
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

    private function product(): Product
    {
        $category = Category::create([
            'name' => 'Phones',
            'slug' => 'phones-'.str()->random(6),
            'status' => 'active',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Apex Phone',
            'slug' => 'apex-phone-'.str()->random(6),
            'sku' => 'APX-'.str()->upper(str()->random(6)),
            'price' => 120000,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    private function orderItem(Product $product, User $user, string $fulfilmentStatus): OrderItem
    {
        $order = Order::create([
            'user_id' => $user->id,
            'number' => 'LMT-'.str()->upper(str()->random(8)),
            'currency' => 'NGN',
            'subtotal' => 120000,
            'grand_total' => 120000,
            'total_amount' => 120000,
            'status' => $fulfilmentStatus === 'delivered' ? 'delivered' : 'processing',
            'payment_status' => 'paid',
            'fulfilment_status' => $fulfilmentStatus,
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '14 Admiralty Way'],
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 120000,
            'price_at_purchase' => 120000,
            'line_total' => 120000,
        ]);
    }

    private function review(Product $product, User $user, int $rating, string $status): Review
    {
        $item = $this->orderItem($product, $user, 'delivered');

        return Review::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'user_id' => $user->id,
            'rating' => $rating,
            'title' => 'Review '.$rating,
            'body' => 'A useful product review.',
            'status' => $status,
            'verified_purchase' => true,
        ]);
    }
}
