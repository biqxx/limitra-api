<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Admin\AuditEvent;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/activity')
            ->assertUnauthorized();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/activity')
            ->assertForbidden();
    }

    public function test_admin_can_list_activity_logs_with_normalized_pagination(): void
    {
        $admin = User::factory()->admin()->create();

        AuditEvent::factory()->count(3)->create([
            'actor_id' => $admin->id,
            'action' => 'business_setting.updated',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/activity');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id',
                            'action',
                            'actor',
                            'before',
                            'after',
                            'created_at',
                        ],
                    ],
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                        'from',
                        'to',
                    ],
                ],
            ])
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_staff_with_audit_logs_permission_can_filter_activity(): void
    {
        $staff = User::factory()->staff()->create();
        $permission = Permission::query()->where('name', 'audit_logs.read')->firstOrFail();
        $role = Role::query()->where('name', 'staff')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $event1 = AuditEvent::factory()->create([
            'action' => 'inventory.adjusted',
            'actor_id' => $staff->id,
        ]);

        $event2 = AuditEvent::factory()->create([
            'action' => 'user.suspended',
            'actor_id' => $staff->id,
        ]);

        $response = $this->actingAs($staff, 'api')
            ->getJson('/api/v1/admin/activity?action=inventory.adjusted');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $event1->id)
            ->assertJsonPath('data.items.0.action', 'inventory.adjusted');
    }

    public function test_can_view_single_activity_log(): void
    {
        $admin = User::factory()->admin()->create();
        $event = AuditEvent::factory()->create([
            'action' => 'order.refunded',
            'actor_id' => $admin->id,
            'reason' => 'Damaged in transit',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/activity/{$event->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.action', 'order.refunded')
            ->assertJsonPath('data.reason', 'Damaged in transit');
    }

    public function test_viewing_nonexistent_activity_log_returns_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/activity/999999')
            ->assertNotFound();
    }
}
