<?php

namespace Tests\Feature\Microstore;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Microstore\Microstore;
use App\Models\Order\Order;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Settings\BusinessSetting;
use App\Models\User;
use App\Services\Microstore\MicrostoreAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MicrostoreDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $admin;

    private Product $product1;

    private Product $product2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->creator = User::factory()->create(['role' => 'user']);
        $this->admin = User::factory()->admin()->create();

        $category = Category::create([
            'name' => 'Fashion & Style',
            'slug' => 'fashion-style',
            'is_active' => true,
        ]);

        $this->product1 = Product::create([
            'category_id' => $category->id,
            'name' => 'Classic Leather Jacket',
            'slug' => 'classic-leather-jacket',
            'price' => 45000,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->product2 = Product::create([
            'category_id' => $category->id,
            'name' => 'Designer Sunglasses',
            'slug' => 'designer-sunglasses',
            'price' => 15000,
            'stock' => 20,
            'status' => 'active',
        ]);
    }

    public function test_creator_can_create_and_manage_microstore_and_products(): void
    {
        // Creator creates microstore
        $createRes = $this->actingAs($this->creator, 'api')
            ->postJson('/api/v1/my-microstore', [
                'name' => 'Alex Fashion Boutique',
                'slug' => 'alex-boutique',
                'tagline' => 'Curated modern fashion for everyday style',
                'bio' => 'Fashion influencer based in Lagos and London.',
                'theme_color' => '#1a1a1a',
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.name', 'Alex Fashion Boutique')
            ->assertJsonPath('data.slug', 'alex-boutique')
            ->assertJsonPath('data.status', 'active');

        $microstoreId = $createRes->json('data.id');

        // Cannot create second microstore
        $duplicateRes = $this->actingAs($this->creator, 'api')
            ->postJson('/api/v1/my-microstore', [
                'name' => 'Another Store',
            ]);
        $duplicateRes->assertStatus(422);

        // Creator updates branding
        $updateRes = $this->actingAs($this->creator, 'api')
            ->patchJson('/api/v1/my-microstore', [
                'tagline' => 'Updated curated tagline',
            ]);
        $updateRes->assertOk()
            ->assertJsonPath('data.tagline', 'Updated curated tagline');

        // Creator adds product
        $addProductRes = $this->actingAs($this->creator, 'api')
            ->postJson('/api/v1/my-microstore/products', [
                'product_id' => $this->product1->id,
                'custom_title' => "Alex's Favorite Leather Jacket",
                'custom_description' => 'Tested in winter and rain, top quality.',
                'is_pinned' => true,
            ]);

        $addProductRes->assertCreated()
            ->assertJsonPath('data.custom_title', "Alex's Favorite Leather Jacket")
            ->assertJsonPath('data.is_pinned', true);

        // Creator adds second product
        $this->actingAs($this->creator, 'api')
            ->postJson('/api/v1/my-microstore/products', [
                'product_id' => $this->product2->id,
            ])->assertCreated();

        // Creator views own microstore
        $viewMyStoreRes = $this->actingAs($this->creator, 'api')
            ->getJson('/api/v1/my-microstore');

        $viewMyStoreRes->assertOk()
            ->assertJsonPath('data.name', 'Alex Fashion Boutique')
            ->assertJsonCount(2, 'data.curated_products');

        // Creator updates product
        $this->actingAs($this->creator, 'api')
            ->patchJson("/api/v1/my-microstore/products/{$this->product1->id}", [
                'custom_title' => 'Updated Custom Title',
            ])->assertOk()
            ->assertJsonPath('data.custom_title', 'Updated Custom Title');

        // Creator removes product 2
        $this->actingAs($this->creator, 'api')
            ->deleteJson("/api/v1/my-microstore/products/{$this->product2->id}")
            ->assertOk();

        $this->assertDatabaseMissing('microstore_products', [
            'microstore_id' => $microstoreId,
            'product_id' => $this->product2->id,
        ]);
    }

    public function test_public_browsing_view_increment_and_click_tracking(): void
    {
        $microstore = Microstore::create([
            'user_id' => $this->creator->id,
            'name' => 'Vintage Vault',
            'slug' => 'vintage-vault',
            'tagline' => 'Best vintage items',
            'status' => 'active',
            'moderation_status' => 'approved',
            'views_count' => 10,
        ]);

        $microstore->products()->attach($this->product1->id, [
            'custom_title' => 'Vintage Jacket',
            'sort_order' => 1,
            'is_pinned' => true,
        ]);

        // Public list
        $this->getJson('/api/v1/microstores')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.slug', 'vintage-vault');

        // Public show increments view count
        $this->getJson('/api/v1/microstores/vintage-vault')
            ->assertOk()
            ->assertJsonPath('data.name', 'Vintage Vault')
            ->assertJsonPath('data.views_count', 11);

        $this->assertEquals(11, $microstore->fresh()->views_count);
        $this->assertDatabaseHas('microstore_events', [
            'microstore_id' => $microstore->id,
            'event_type' => 'view',
        ]);

        // Public curated products
        $this->getJson('/api/v1/microstores/vintage-vault/products')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.custom_title', 'Vintage Jacket');

        // Public track click
        $this->postJson('/api/v1/microstores/vintage-vault/clicks', [
            'product_id' => $this->product1->id,
            'session_id' => 'sess_abc123',
        ])->assertOk();

        $this->assertDatabaseHas('microstore_events', [
            'microstore_id' => $microstore->id,
            'event_type' => 'product_click',
            'product_id' => $this->product1->id,
        ]);

        // Creator checks analytics
        $analyticsRes = $this->actingAs($this->creator, 'api')
            ->getJson('/api/v1/my-microstore/analytics');

        $analyticsRes->assertOk()
            ->assertJsonPath('data.views_count', 11)
            ->assertJsonPath('data.clicks_count', 1);
    }

    public function test_admin_microstore_moderation_and_access_gate(): void
    {
        $microstore = Microstore::create([
            'user_id' => $this->creator->id,
            'name' => 'Flagged Store',
            'slug' => 'flagged-store',
            'status' => 'active',
            'moderation_status' => 'approved',
        ]);

        // Admin lists microstores
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/microstores')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        // Admin suspends microstore
        $modRes = $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/microstores/{$microstore->id}/moderate", [
                'moderation_status' => 'suspended',
                'moderation_notes' => 'Suspended due to policy violation in product descriptions',
            ]);

        $modRes->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.moderation_status', 'suspended');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'microstore.moderated',
            'reason' => 'suspended',
        ]);

        // Suspended store is no longer accessible publicly
        $this->getJson('/api/v1/microstores/flagged-store')
            ->assertNotFound();

        // Admin re-approves microstore
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/microstores/{$microstore->id}/moderate", [
                'moderation_status' => 'approved',
            ])->assertOk()
            ->assertJsonPath('data.status', 'active');

        // Public access restored
        $this->getJson('/api/v1/microstores/flagged-store')
            ->assertOk()
            ->assertJsonPath('data.name', 'Flagged Store');
    }

    public function test_public_store_hides_inactive_curated_products_and_supports_moderation_setting(): void
    {
        BusinessSetting::query()->where('key', 'microstores.require_moderation')->update([
            'value' => true,
        ]);
        Cache::forget('business_settings.values.v1');

        $pendingStoreRes = $this->actingAs($this->creator, 'api')
            ->postJson('/api/v1/my-microstore', [
                'name' => 'Celebrity Pending Store',
                'slug' => 'celebrity-pending-store',
            ]);

        $pendingStoreRes->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.moderation_status', 'pending');

        $microstoreId = (int) $pendingStoreRes->json('data.id');

        // Not publicly visible before approval
        $this->getJson('/api/v1/microstores/celebrity-pending-store')->assertNotFound();

        // Admin approves
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/admin/microstores/{$microstoreId}/moderate", [
                'moderation_status' => 'approved',
            ])->assertOk();

        // Add active products, then archive product2
        $this->actingAs($this->creator, 'api')
            ->postJson('/api/v1/my-microstore/products', ['product_id' => $this->product1->id])
            ->assertCreated();
        $this->actingAs($this->creator, 'api')
            ->postJson('/api/v1/my-microstore/products', ['product_id' => $this->product2->id])
            ->assertCreated();

        $this->product2->update(['status' => 'draft']);

        // Public show and products only return active product1
        $this->getJson('/api/v1/microstores/celebrity-pending-store')
            ->assertOk()
            ->assertJsonCount(1, 'data.curated_products')
            ->assertJsonPath('data.curated_products.0.product_id', $this->product1->id);

        $this->getJson('/api/v1/microstores/celebrity-pending-store/products')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.product_id', $this->product1->id);
    }

    public function test_admin_can_create_celebrity_store_and_orders_attribute_and_reverse_revenue(): void
    {
        $buyer = User::factory()->create(['role' => 'user']);

        $createRes = $this->actingAs($this->admin, 'api')
            ->postJson('/api/v1/admin/microstores', [
                'user_id' => $this->creator->id,
                'name' => 'Davido Official Store',
                'slug' => 'davido-official',
                'tagline' => 'Official celebrity picks',
                'is_featured' => true,
                'product_ids' => [$this->product1->id],
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.slug', 'davido-official')
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonCount(1, 'data.curated_products');

        $microstore = Microstore::where('slug', 'davido-official')->firstOrFail();

        $order = Order::create([
            'user_id' => $buyer->id,
            'microstore_id' => $microstore->id,
            'number' => 'LMT-CELEB-001',
            'currency' => 'NGN',
            'subtotal' => 45000,
            'discount_total' => 0,
            'credit_total' => 0,
            'shipping_total' => 2500,
            'grand_total' => 47500,
            'total_amount' => 47500,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfilment_status' => 'unfulfilled',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Lagos'],
        ]);

        $order->items()->create([
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'sku' => 'SKU-JKT-01',
            'quantity' => 1,
            'unit_price' => 45000,
            'price_at_purchase' => 45000,
            'line_total' => 45000,
        ]);

        $attribution = app(MicrostoreAttributionService::class);
        $attribution->recordOrder($order);
        // Idempotent second call
        $attribution->recordOrder($order);

        $microstore->refresh();
        $this->assertSame(1, $microstore->orders_count);
        $this->assertSame('45000.00', $microstore->total_revenue);

        // Reversal on cancellation/refund
        $attribution->reverseOrder($order, 'order_fully_refunded');
        $microstore->refresh();
        $this->assertSame(0, $microstore->orders_count);
        $this->assertSame('0.00', $microstore->total_revenue);
    }
}
