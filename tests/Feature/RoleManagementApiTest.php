<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Admin\AuditEvent;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_administrator_can_list_roles_and_permissions(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/roles')
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'admin'])->assertJsonFragment(['name' => 'staff']);

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/permissions')
            ->assertOk()->assertJsonFragment(['name' => 'roles.manage']);

        $this->actingAs(User::factory()->create(), 'api')->getJson('/api/v1/admin/roles')
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_administrator_can_create_update_and_delete_a_custom_role(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/roles', [
            'name' => 'Support Manager',
            'display_name' => 'Support manager',
            'description' => 'Handles escalations.',
            'permissions' => ['support.read', 'support.manage'],
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'support_manager')
            ->assertJsonCount(2, 'data.permissions');
        $role = Role::query()->where('name', 'support_manager')->firstOrFail();

        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/roles/{$role->id}", [
            'display_name' => 'Escalation manager',
            'permissions' => ['support.manage'],
        ])->assertOk()->assertJsonPath('data.display_name', 'Escalation manager');

        $this->assertSame(['support.manage'], $role->fresh()->permissions()->pluck('name')->all());
        $this->assertDatabaseHas('audit_events', ['action' => 'role.created', 'subject_id' => $role->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'role.updated', 'subject_id' => $role->id]);

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/roles/{$role->id}")->assertNoContent();
        $this->assertModelMissing($role);
        $this->assertSame(1, AuditEvent::query()->where('action', 'role.deleted')->count());
    }

    public function test_system_and_assigned_roles_cannot_be_modified_or_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $systemRole = Role::query()->where('name', 'staff')->firstOrFail();

        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/roles/{$systemRole->id}", [
            'display_name' => 'Changed',
        ])->assertConflict()->assertJsonPath('code', 'CONFLICT');
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/roles/{$systemRole->id}")
            ->assertConflict();

        $role = Role::factory()->create();
        $staff = User::factory()->staff()->create();
        $staff->roles()->attach($role, ['is_primary' => false, 'assigned_at' => now()]);

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/roles/{$role->id}")
            ->assertConflict();
    }

    public function test_delegated_manager_cannot_grant_permissions_they_do_not_hold(): void
    {
        $manager = User::factory()->staff()->create();
        $managerRole = Role::factory()->create(['name' => 'role_manager']);
        $managerRole->permissions()->attach(Permission::query()->whereIn('name', [
            'roles.manage', 'analytics.read',
        ])->get());
        $manager->roles()->attach($managerRole, ['is_primary' => false, 'assigned_at' => now()]);

        $this->actingAs($manager, 'api')->postJson('/api/v1/admin/roles', [
            'name' => 'analyst', 'display_name' => 'Analyst', 'permissions' => ['analytics.read'],
        ])->assertCreated();

        $this->actingAs($manager, 'api')->postJson('/api/v1/admin/roles', [
            'name' => 'report_exporter',
            'display_name' => 'Report exporter',
            'permissions' => ['reports.download'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'report_exporter']);
    }
}
