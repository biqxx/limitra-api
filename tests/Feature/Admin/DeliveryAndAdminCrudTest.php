<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Commerce\DeliveryZone;
use App\Models\Content\CareerJob;
use App\Models\Content\NewsletterSubscription;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\Review;
use App\Models\Support\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryAndAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_can_manage_delivery_zones(): void
    {
        $admin = $this->createAdmin();

        // 1. Create Zone
        $createRes = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/delivery-zones', [
            'name' => 'South West',
            'country' => 'NG',
            'states' => ['Lagos', 'Ogun', 'Oyo'],
            'priority' => 5,
            'active' => true,
        ]);
        $createRes->assertCreated()->assertJsonPath('data.name', 'South West');
        $zoneId = $createRes->json('data.id');

        // 2. Show Zone
        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/delivery-zones/{$zoneId}")
            ->assertOk()
            ->assertJsonPath('data.id', $zoneId)
            ->assertJsonPath('data.name', 'South West');

        // 3. Update Zone
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/delivery-zones/{$zoneId}", [
            'name' => 'South West Nigeria',
            'priority' => 8,
        ])->assertOk()->assertJsonPath('data.name', 'South West Nigeria');

        // 4. Delete Zone
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/delivery-zones/{$zoneId}")
            ->assertOk();
        $this->assertDatabaseMissing('delivery_zones', ['id' => $zoneId]);
    }

    public function test_admin_can_manage_delivery_methods_and_pricing(): void
    {
        $admin = $this->createAdmin();
        $zone = DeliveryZone::create(['name' => 'Lagos Zone', 'country' => 'NG', 'states' => ['Lagos']]);

        // 1. Create Method
        $res = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/delivery-methods', [
            'code' => 'dispatch_bike',
            'name' => 'Motorcycle Courier',
            'type' => 'express',
            'active' => true,
        ]);
        $res->assertCreated();
        $methodId = $res->json('data.id');

        // 2. Show Method
        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/delivery-methods/{$methodId}")
            ->assertOk()
            ->assertJsonPath('data.code', 'dispatch_bike');

        // 3. Set Zone Method Pricing
        $this->actingAs($admin, 'api')->putJson("/api/v1/admin/delivery-zones/{$zone->id}/methods/{$methodId}", [
            'fee' => 1500,
            'free_shipping_threshold' => 50000,
            'estimated_days_min' => 1,
            'estimated_days_max' => 2,
            'active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('delivery_zone_method', [
            'delivery_zone_id' => $zone->id,
            'delivery_method_id' => $methodId,
            'fee' => 1500,
        ]);

        // 4. Detach Method from Zone
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/delivery-zones/{$zone->id}/methods/{$methodId}")
            ->assertOk();
        $this->assertDatabaseMissing('delivery_zone_method', [
            'delivery_zone_id' => $zone->id,
            'delivery_method_id' => $methodId,
        ]);

        // 5. Delete Method
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/delivery-methods/{$methodId}")
            ->assertOk();
        $this->assertDatabaseMissing('delivery_methods', ['id' => $methodId]);
    }

    public function test_admin_can_manage_pickup_locations(): void
    {
        $admin = $this->createAdmin();
        $zone = DeliveryZone::create(['name' => 'Lagos Zone', 'country' => 'NG', 'states' => ['Lagos']]);

        // 1. Create Pickup Location
        $res = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/pickup-locations', [
            'delivery_zone_id' => $zone->id,
            'code' => 'LEKKI_HUB',
            'name' => 'Lekki Phase 1 Hub',
            'line1' => '10 Admiralty Way',
            'city' => 'Lekki',
            'state' => 'Lagos',
            'country' => 'NG',
            'active' => true,
        ]);
        $res->assertCreated();
        $pickupId = $res->json('data.id');

        // 2. Show Pickup Location
        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/pickup-locations/{$pickupId}")
            ->assertOk()
            ->assertJsonPath('data.code', 'LEKKI_HUB');

        // 3. Update Pickup Location
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/pickup-locations/{$pickupId}", [
            'name' => 'Lekki Admiralty Hub',
        ])->assertOk()->assertJsonPath('data.name', 'Lekki Admiralty Hub');

        // 4. Delete Pickup Location
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/pickup-locations/{$pickupId}")
            ->assertOk();
        $this->assertDatabaseMissing('pickup_locations', ['id' => $pickupId]);
    }

    public function test_admin_can_manage_categories_under_admin_prefix(): void
    {
        $admin = $this->createAdmin();

        // 1. Create Category
        $res = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/categories', [
            'name' => 'Electronics & Gadgets',
            'description' => 'Top consumer tech',
            'active' => true,
        ]);
        $res->assertCreated();
        $categoryId = $res->json('data.id');

        // 2. Show Category
        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('data.id', $categoryId)
            ->assertJsonPath('data.name', 'Electronics & Gadgets');

        // 3. Update Category
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/categories/{$categoryId}", [
            'name' => 'Smart Electronics',
        ])->assertOk()->assertJsonPath('data.name', 'Smart Electronics');

        // 4. Delete Category
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/categories/{$categoryId}")
            ->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $categoryId]);
    }

    public function test_admin_can_moderate_and_delete_reviews(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser('reviewer@example.com');
        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel', 'active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Sneakers',
            'slug' => 'sneakers',
            'sku' => 'SNK-001',
            'price' => 50000,
            'currency' => 'NGN',
            'stock' => 10,
            'status' => 'active',
        ]);

        $order = Order::create([
            'number' => 'ORD-REV-100',
            'user_id' => $customer->id,
            'currency' => 'NGN',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'total_amount' => 50000,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'fulfilment_status' => 'delivered',
            'payment_method' => 'card',
            'contact_email' => $customer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '14 Admiralty Way'],
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 50000,
            'price_at_purchase' => 50000,
            'line_total' => 50000,
        ]);

        $review = Review::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'user_id' => $customer->id,
            'rating' => 5,
            'title' => 'Great product',
            'body' => 'I love these sneakers so much!',
            'status' => 'pending',
            'verified_purchase' => true,
        ]);

        // 1. List reviews
        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/reviews?status=pending')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $review->id);

        // 2. Show review
        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/reviews/{$review->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $review->id);

        // 3. Moderate review
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/reviews/{$review->id}/moderate", [
            'status' => 'published',
            'reason' => 'Compliant review',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        // 4. Delete review
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/reviews/{$review->id}")
            ->assertOk();
        $this->assertSoftDeleted('reviews', ['id' => $review->id]);
    }

    public function test_admin_can_view_ticket_and_reply_to_customer(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createUser('ticket_user@example.com');
        $ticket = SupportTicket::create([
            'number' => 'TCK-1001',
            'user_id' => $customer->id,
            'contact_name' => 'Ticket User',
            'contact_email' => $customer->email,
            'category' => 'technical',
            'subject' => 'Where is my order?',
            'status' => 'open',
            'priority' => 'normal',
            'first_response_due_at' => now()->addHours(2),
            'resolution_due_at' => now()->addHours(24),
            'last_message_at' => now(),
        ]);

        // 1. Show ticket
        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/support/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.number', 'TCK-1001');

        // 2. Reply to ticket
        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/support/tickets/{$ticket->id}/messages", [
            'message' => 'Hello, your order has shipped and will arrive tomorrow.',
        ])->assertCreated();

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_id' => $admin->id,
            'sender_type' => 'staff',
        ]);
    }

    public function test_admin_can_manage_jobs_and_newsletter(): void
    {
        $admin = $this->createAdmin();

        // 1. Career Job
        $jobRes = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/jobs', [
            'title' => 'Senior Backend Engineer',
            'department' => 'Engineering',
            'location' => 'Remote',
            'type' => 'full-time',
            'description' => 'Build high-throughput APIs',
            'status' => 'open',
        ]);
        $jobRes->assertCreated();
        $jobId = $jobRes->json('data.id');

        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.id', $jobId);

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/jobs/{$jobId}")
            ->assertOk();
        $this->assertDatabaseMissing('career_jobs', ['id' => $jobId]);

        // 2. Newsletter subscriber
        $sub = NewsletterSubscription::create([
            'email' => 'subscriber@example.com',
            'token' => 'abc123token',
            'status' => 'subscribed',
            'consented_at' => now(),
        ]);

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/newsletter/subscribers')
            ->assertOk()
            ->assertJsonPath('data.items.0.email', 'subscriber@example.com');

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/newsletter/subscribers/{$sub->id}")
            ->assertOk();
        $this->assertDatabaseMissing('newsletter_subscriptions', ['id' => $sub->id]);
    }

    public function test_storefront_home_and_careers_caching_and_invalidation(): void
    {
        Cache::flush();

        // 1. Home cache test
        $homeRes1 = $this->getJson('/api/v1/home')->assertOk();
        $this->assertTrue(Cache::has('storefront:home:v1:web'));

        // Modify content entry to trigger invalidation
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/content', [
            'title' => 'New Banner',
            'status' => 'published',
        ])->assertCreated();

        // Version should have rotated
        $newVersion = Cache::get('storefront:home:version');
        $this->assertNotEquals('1', $newVersion);

        // 2. Career jobs cache test
        CareerJob::create([
            'title' => 'Product Manager',
            'slug' => 'product-manager',
            'department' => 'Product',
            'location' => 'Remote',
            'type' => 'full-time',
            'description' => 'PM role',
            'status' => 'open',
        ]);

        $this->getJson('/api/v1/jobs')->assertOk();
        $vJobs = Cache::get('careers:jobs:version', '1');
        $this->assertNotNull($vJobs);

        // Update job triggers invalidation
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/jobs', [
            'title' => 'QA Engineer',
            'department' => 'QA',
            'description' => 'Test systems',
            'status' => 'open',
        ])->assertCreated();

        $newJobVersion = Cache::get('careers:jobs:version');
        $this->assertNotEquals($vJobs, $newJobVersion);

        // 3. Closed job posting rejects applications
        $closedJob = CareerJob::create([
            'title' => 'Closed Role',
            'slug' => 'closed-role',
            'department' => 'Engineering',
            'location' => 'Remote',
            'type' => 'full-time',
            'description' => 'Closed role',
            'status' => 'closed',
        ]);

        $this->postJson("/api/v1/jobs/{$closedJob->id}/applications", [
            'name' => 'Applicant',
            'email' => 'applicant@example.com',
            'cv_url' => 'https://example.com/cv.pdf',
        ])->assertStatus(422);
    }

    private function createAdmin(): User
    {
        $admin = User::create([
            'username' => 'admin_'.uniqid(),
            'email' => 'admin_'.uniqid().'@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $roleId = DB::table('roles')->where('name', 'admin')->value('id');
        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'admin',
                'display_name' => 'Administrator',
                'is_system' => true,
                'is_super_admin' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('role_user')->insertOrIgnore([
            'user_id' => $admin->id,
            'role_id' => $roleId,
        ]);

        $perms = [
            'delivery.read', 'delivery.manage', 'promotions.read', 'promotions.manage',
            'reviews.moderate', 'support.read', 'support.manage', 'rewards.read', 'rewards.manage',
        ];
        foreach ($perms as $slug) {
            $permId = DB::table('permissions')->where('name', $slug)->value('id');
            if (! $permId) {
                $permId = DB::table('permissions')->insertGetId([
                    'name' => $slug,
                    'domain' => 'admin',
                    'description' => $slug,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }

        return $admin;
    }

    private function createUser(string $email): User
    {
        return User::create([
            'username' => (string) str($email)->before('@'),
            'email' => $email,
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }
}
