<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Commerce\Promotion;
use App\Models\Commerce\PromotionRedemption;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionAdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_can_list_filter_and_paginate_promotions(): void
    {
        $admin = $this->createAdminUser(['promotions.read']);

        $p1 = Promotion::create([
            'code' => 'SUMMER20',
            'name' => 'Summer Sale',
            'type' => 'percentage',
            'value' => 20,
            'active' => true,
        ]);
        $p2 = Promotion::create([
            'code' => 'FLAT500',
            'name' => 'Flat Discount',
            'type' => 'fixed',
            'value' => 500,
            'active' => false,
        ]);

        // Filter by search
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/promotions?search=summer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SUMMER20');

        // Filter by active with pagination
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/promotions?active=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.code', 'SUMMER20');
    }

    public function test_admin_can_view_promotion_details_with_statistics(): void
    {
        $admin = $this->createAdminUser(['promotions.read']);
        $customer = User::factory()->create(['role' => 'user']);

        $promotion = Promotion::create([
            'code' => 'STATSTEST',
            'name' => 'Statistics Test',
            'type' => 'percentage',
            'value' => 15,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'number' => 'ORD-PROM-1',
            'currency' => 'NGN',
            'subtotal' => '1000.00',
            'discount_total' => '150.00',
            'credit_total' => '0.00',
            'shipping_total' => '100.00',
            'grand_total' => '950.00',
            'total_amount' => '950.00',
            'status' => 'delivered',
            'payment_status' => 'paid',
            'fulfilment_status' => 'delivered',
            'payment_method' => 'card',
            'contact_email' => $customer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['recipient_name' => 'Prom User'],
        ]);

        PromotionRedemption::create([
            'promotion_id' => $promotion->id,
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'discount_amount' => 150.00,
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/promotions/{$promotion->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'STATSTEST')
            ->assertJsonPath('data.statistics.total_redemptions', 1)
            ->assertJsonPath('data.statistics.total_discount_amount', '150.00')
            ->assertJsonPath('data.statistics.unique_customers', 1);
    }

    public function test_admin_can_view_promotion_redemptions(): void
    {
        $admin = $this->createAdminUser(['promotions.read']);
        $customer = User::factory()->create(['username' => 'redeemer', 'role' => 'user']);

        $promotion = Promotion::create([
            'code' => 'REDEMPTION10',
            'name' => 'Redemption 10',
            'type' => 'fixed',
            'value' => 100,
            'active' => true,
        ]);

        PromotionRedemption::create([
            'promotion_id' => $promotion->id,
            'user_id' => $customer->id,
            'order_id' => null,
            'discount_amount' => 100.00,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/promotions/{$promotion->id}/redemptions")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.discount_amount', '100.00');
    }

    public function test_admin_can_soft_archive_promotion(): void
    {
        $admin = $this->createAdminUser(['promotions.manage']);

        $promotion = Promotion::create([
            'code' => 'ARCHIVEME',
            'name' => 'Archive Me',
            'type' => 'percentage',
            'value' => 10,
            'active' => true,
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/promotions/{$promotion->id}")
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertFalse($promotion->fresh()->active);
        // Ensure record is preserved
        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'active' => 0]);
    }

    private function createAdminUser(array $permissions): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $roleId = DB::table('roles')->where('name', 'admin')->value('id');
        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'admin',
                'display_name' => 'Administrator',
                'is_system' => true,
                'is_super_admin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('role_user')->insertOrIgnore([
            'user_id' => $admin->id,
            'role_id' => $roleId,
        ]);

        foreach ($permissions as $perm) {
            $permId = DB::table('permissions')->where('name', $perm)->value('id');
            if (! $permId) {
                $permId = DB::table('permissions')->insertGetId([
                    'name' => $perm,
                    'domain' => 'promotions',
                    'description' => $perm,
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
}
