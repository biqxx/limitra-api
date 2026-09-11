<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Models\User\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMembershipApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_administrator_can_add_list_and_remove_a_staff_role_member(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $role = Role::factory()->create(['name' => 'fulfilment_lead']);

        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/roles/{$role->id}/members", [
            'user_id' => $staff->id,
        ])->assertCreated()->assertJsonPath('data.id', $staff->id)
            ->assertJsonPath('data.is_primary', false);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $staff->id, 'role_id' => $role->id,
            'assigned_by' => $admin->id, 'is_primary' => false,
        ]);

        $this->actingAs($admin, 'api')->getJson("/api/v1/admin/roles/{$role->id}/members")
            ->assertOk()->assertJsonPath('data.data.0.id', $staff->id);
        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/roles/{$role->id}/members", [
            'user_id' => $staff->id,
        ])->assertConflict();

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/roles/{$role->id}/members/{$staff->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('role_user', ['user_id' => $staff->id, 'role_id' => $role->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'role.member_added', 'subject_id' => $staff->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'role.member_removed', 'subject_id' => $staff->id]);
    }

    public function test_custom_roles_can_only_be_assigned_to_staff_and_system_membership_is_protected(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $customRole = Role::factory()->create();

        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/roles/{$customRole->id}/members", [
            'user_id' => $customer->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $staffRole = Role::query()->where('name', 'staff')->firstOrFail();
        $staff = User::factory()->staff()->create();
        $this->actingAs($admin, 'api')->deleteJson(
            "/api/v1/admin/roles/{$staffRole->id}/members/{$staff->id}",
        )->assertConflict();
    }

    public function test_replacing_staff_operational_role_preserves_primary_system_role(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $oldRole = Role::factory()->create(['name' => 'old_operator']);
        $newRole = Role::factory()->create(['name' => 'new_operator']);
        $staff->roles()->attach($oldRole, ['is_primary' => false, 'assigned_at' => now()]);

        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/staff/{$staff->id}/role", [
            'role_id' => $newRole->id,
        ])->assertOk();

        $this->assertSame(
            ['new_operator', 'staff'],
            $staff->fresh()->roles()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertDatabaseHas('role_user', [
            'user_id' => $staff->id,
            'role_id' => Role::query()->where('name', 'staff')->value('id'),
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.operational_role_changed', 'subject_id' => $staff->id,
        ]);
    }
}
