<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\UserDirectoryController;
use App\Http\Middleware\TrackAnalytics;
use App\Jobs\SendStaffInvitation;
use App\Models\User;
use App\Models\User\Role;
use App\Models\User\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUserInvitationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('u', 32)),
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
        Queue::fake();
    }

    public function test_admin_user_creation_queues_private_invitation_by_normalized_role_name(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::factory()->create(['name' => 'support_lead']);

        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/users', [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.test',
            'role' => 'Support Lead',
            'invite' => true,
        ])->assertCreated()
            ->assertJsonPath('message', 'User invitation queued.')
            ->assertJsonPath('data.email', 'a***@example.test')
            ->assertJsonPath('data.role.name', 'support_lead')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.email_ciphertext');

        $invitation = StaffInvitation::query()->firstOrFail();
        $this->assertSame($role->id, $invitation->role_id);
        $this->assertSame('ada@example.test', $invitation->email_ciphertext);
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.test']);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.invitation_created',
            'actor_id' => $admin->id,
            'subject_id' => $invitation->id,
        ]);

        Queue::assertPushed(SendStaffInvitation::class, fn (SendStaffInvitation $job): bool => $job->invitationId === $invitation->id && $job->queue === 'notifications');
    }

    public function test_admin_user_creation_rejects_plaintext_credentials(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/users', [
            'name' => 'Unsafe Account',
            'email' => 'unsafe@example.test',
            'username' => 'unsafe_account',
            'password' => 'plaintext-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'password']);

        $this->assertDatabaseMissing('users', ['email' => 'unsafe@example.test']);
        $this->assertDatabaseCount('staff_invitations', 0);
        Queue::assertNothingPushed();
    }

    public function test_admin_user_store_route_uses_invitation_controller_and_permission(): void
    {
        $route = Route::getRoutes()->getByName('admin.users.store');

        $this->assertNotNull($route);
        $this->assertSame(UserDirectoryController::class.'@store', $route->getActionName());
        $this->assertContains('active.session', $route->gatherMiddleware());
        $this->assertContains('permission:roles.manage', $route->gatherMiddleware());
    }
}
