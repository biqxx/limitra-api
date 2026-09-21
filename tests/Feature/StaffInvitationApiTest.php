<?php

namespace Tests\Feature;

use App\Enums\StaffInvitationStatus;
use App\Http\Middleware\TrackAnalytics;
use App\Jobs\SendStaffInvitation;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Models\User\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaffInvitationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('i', 32)),
            'app.frontend_url' => 'https://shop.example.test',
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
        Queue::fake();
    }

    public function test_administrator_can_create_and_list_a_private_queued_invitation(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::factory()->create(['name' => 'support_lead']);

        $response = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.test',
            'role_id' => $role->id,
        ])->assertCreated()
            ->assertJsonPath('data.email', 'a***@example.test')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.role.name', 'support_lead')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.email_ciphertext');

        $invitation = StaffInvitation::query()->firstOrFail();
        $this->assertSame('ada@example.test', $invitation->email_ciphertext);
        $this->assertNotSame('ada@example.test', DB::table('staff_invitations')->value('email_ciphertext'));

        Queue::assertPushed(SendStaffInvitation::class, function (SendStaffInvitation $job) use ($invitation): bool {
            $serialized = serialize($job);
            $this->assertStringNotContainsString('ada@example.test', $serialized);
            $this->assertStringNotContainsString($invitation->token_ciphertext, $serialized);
            $this->assertSame('notifications', $job->queue);

            return $job->invitationId === $invitation->id
                && $job->deliveryVersion === $invitation->delivery_version;
        });

        $this->actingAs($admin, 'api')->getJson('/api/v1/admin/staff/invitations')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $response->json('data.id'))
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonMissing(['email_ciphertext' => 'ada@example.test']);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.invitation_created',
            'subject_id' => $invitation->id,
        ]);
    }

    public function test_role_member_endpoint_can_invite_and_accept_new_staff(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::factory()->create(['name' => 'customer_support']);

        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/roles/{$role->id}/members", [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.test',
        ])->assertCreated()->assertJsonPath('data.role.name', 'customer_support');

        $invitation = StaffInvitation::query()->firstOrFail();
        $token = $invitation->token_ciphertext;

        $this->postJson('/api/v1/staff/invitations/accept', [
            'token' => $token,
            'username' => 'grace_hopper',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
            'phone' => '+2348000000000',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'grace@example.test')
            ->assertJsonPath('data.role', 'staff');

        $staff = User::query()->where('email', 'grace@example.test')->firstOrFail();
        $this->assertNotNull($staff->email_verified_at);
        $this->assertSame(['customer_support', 'staff'], $staff->roles()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('role_user', [
            'user_id' => $staff->id,
            'role_id' => Role::query()->where('name', 'staff')->value('id'),
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $staff->id,
            'role_id' => $role->id,
            'assigned_by' => $admin->id,
            'is_primary' => false,
        ]);
        $this->assertSame(StaffInvitationStatus::Accepted, $invitation->fresh()->status);
        $this->assertNull($invitation->fresh()->token_hash);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.invitation_accepted',
            'subject_id' => $staff->id,
        ]);

        $this->postJson('/api/v1/staff/invitations/accept', [
            'token' => $token,
            'username' => 'second_click',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_resend_rotates_the_token_and_revoke_invalidates_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'New Staff',
            'email' => 'staff@example.test',
        ])->assertCreated();
        $invitation = StaffInvitation::query()->firstOrFail();
        $oldToken = $invitation->token_ciphertext;
        $oldVersion = $invitation->delivery_version;

        $this->actingAs($admin, 'api')->postJson(
            "/api/v1/admin/staff/invitations/{$invitation->public_id}/resend",
        )->assertAccepted();

        $invitation->refresh();
        $this->assertNotSame($oldToken, $invitation->token_ciphertext);
        $this->assertSame($oldVersion + 1, $invitation->delivery_version);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.invitation_resent']);

        $this->postJson('/api/v1/staff/invitations/accept', [
            'token' => $oldToken,
            'username' => 'old_token',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $newToken = $invitation->token_ciphertext;
        $this->actingAs($admin, 'api')->deleteJson(
            "/api/v1/admin/staff/invitations/{$invitation->public_id}",
            ['reason' => 'Position closed.'],
        )->assertNoContent();

        $this->postJson('/api/v1/staff/invitations/accept', [
            'token' => $newToken,
            'username' => 'revoked_token',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.invitation_revoked',
            'reason' => 'Position closed.',
        ]);
    }

    public function test_existing_accounts_and_duplicate_open_invitations_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'exists@example.test']);

        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Existing User', 'email' => 'exists@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $payload = ['name' => 'Pending Staff', 'email' => 'pending@example.test'];
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/staff/invitations', $payload)
            ->assertCreated();
        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/staff/invitations', $payload)
            ->assertConflict();
        $this->assertDatabaseCount('staff_invitations', 1);
    }

    public function test_delegated_manager_cannot_invite_into_a_role_with_unheld_permissions(): void
    {
        $manager = User::factory()->staff()->create();
        $managerRole = Role::factory()->create(['name' => 'staff_manager']);
        $managerRole->permissions()->attach(Permission::query()->where('name', 'roles.manage')->firstOrFail());
        $manager->roles()->attach($managerRole, ['is_primary' => false, 'assigned_at' => now()]);

        $elevatedRole = Role::factory()->create(['name' => 'report_manager']);
        $elevatedRole->permissions()->attach(Permission::query()->where('name', 'reports.download')->firstOrFail());

        $this->actingAs($manager, 'api')->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Elevated User',
            'email' => 'elevated@example.test',
            'role_id' => $elevatedRole->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_system_roles_cannot_be_assigned_by_staff_invitations(): void
    {
        $admin = User::factory()->admin()->create();
        $staffRole = Role::query()->where('name', 'staff')->firstOrFail();

        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'System Staff',
            'email' => 'system-staff@example.test',
            'role_id' => $staffRole->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');

        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_invitation_is_rejected_if_inviter_loses_authority_before_acceptance(): void
    {
        $manager = User::factory()->staff()->create();
        $managerRole = Role::factory()->create(['name' => 'staff_manager']);
        $permission = Permission::query()->where('name', 'roles.manage')->firstOrFail();
        $managerRole->permissions()->attach($permission);
        $manager->roles()->attach($managerRole, ['is_primary' => false, 'assigned_at' => now()]);

        $this->actingAs($manager, 'api')->postJson('/api/v1/admin/staff/invitations', [
            'name' => 'Late Staff', 'email' => 'late@example.test',
        ])->assertCreated();
        $token = StaffInvitation::query()->firstOrFail()->token_ciphertext;
        $managerRole->permissions()->detach($permission);

        $this->postJson('/api/v1/staff/invitations/accept', [
            'token' => $token,
            'username' => 'late_staff',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertDatabaseMissing('users', ['email' => 'late@example.test']);
    }
}
