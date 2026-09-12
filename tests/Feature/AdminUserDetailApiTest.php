<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\Admin\UserDirectoryController;
use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Services\Admin\AuditEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUserDetailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_views_currency_safe_order_summary_and_redacted_activity(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $ngnOrder = $this->createOrder($customer, 'NGN-1', 'NGN', '1000.00', 'delivered', '2026-09-10 12:00:00');
        $this->createOrder($customer, 'NGN-CANCELLED', 'NGN', '500.00', 'cancelled', '2026-09-11 12:00:00');
        $usdOrder = $this->createOrder($customer, 'USD-1', 'USD', '25.50', 'confirmed', '2026-09-12 12:00:00');

        app(AuditEventService::class)->record(
            action: 'user.reviewed',
            actor: $admin,
            subject: $customer,
            reason: 'Routine review.',
            metadata: ['token' => 'must-not-leak'],
        );

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/users/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $customer->id)
            ->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.summary.orders_count', 3)
            ->assertJsonPath('data.summary.qualifying_orders_count', 2)
            ->assertJsonPath('data.summary.lifetime_value.0.currency', 'NGN')
            ->assertJsonPath('data.summary.lifetime_value.0.amount', '1000.00')
            ->assertJsonPath('data.summary.lifetime_value.1.currency', 'USD')
            ->assertJsonPath('data.summary.lifetime_value.1.amount', '25.50')
            ->assertJsonPath('data.recent_orders.0.id', $usdOrder->id)
            ->assertJsonPath('data.recent_orders.2.id', $ngnOrder->id)
            ->assertJsonPath('data.recent_activity.0.action', 'user.reviewed')
            ->assertJsonPath('data.recent_activity.0.actor.id', $admin->id)
            ->assertJsonPath('data.recent_activity.0.reason', 'Routine review.');

        $this->assertContains('account.manage', $response->json('data.user.permissions'));
        $this->assertArrayNotHasKey('metadata', $response->json('data.recent_activity.0'));
        $this->assertArrayNotHasKey('ip_address', $response->json('data.recent_activity.0'));
        $this->assertArrayNotHasKey('before_values', $response->json('data.recent_activity.0'));
    }

    public function test_admin_can_view_a_soft_deactivated_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->forceFill([
            'status' => UserStatus::Deactivated,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Closed account.',
            'deactivated_by' => $admin->id,
        ])->save();
        $user->delete();

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.status', 'deactivated')
            ->assertJsonPath('data.user.deactivation_reason', 'Closed account.');
    }

    public function test_user_detail_requires_customer_read_permission(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = User::factory()->create();
        $customerRead = Permission::query()->where('name', 'customers.read')->firstOrFail();
        Role::query()->where('name', 'staff')->firstOrFail()->permissions()->detach($customerRead);

        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/admin/users/{$customer->id}")
            ->assertForbidden();
    }

    public function test_user_detail_route_replaces_legacy_controller_and_includes_trashed(): void
    {
        $route = Route::getRoutes()->getByName('admin.users.show');

        $this->assertNotNull($route);
        $this->assertSame(UserDirectoryController::class.'@show', $route->getActionName());
        $this->assertContains('active.session', $route->gatherMiddleware());
        $this->assertContains('permission:customers.read', $route->gatherMiddleware());
        $this->assertTrue($route->allowsTrashedBindings());
    }

    private function createOrder(
        User $user,
        string $number,
        string $currency,
        string $grandTotal,
        string $status,
        string $createdAt,
    ): Order {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'number' => $number,
            'currency' => $currency,
            'subtotal' => $grandTotal,
            'discount_total' => '0.00',
            'credit_total' => '0.00',
            'shipping_total' => '0.00',
            'grand_total' => $grandTotal,
            'total_amount' => $grandTotal,
            'status' => $status,
            'payment_status' => $status === 'cancelled' ? 'failed' : 'paid',
            'fulfilment_status' => $status,
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => [],
        ]);

        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $order;
    }
}
