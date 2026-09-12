<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Http\Middleware\TrackAnalytics;
use App\Models\Admin\AuditEvent;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Services\Auth\AuthSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Cache::flush();
        Notification::fake();
    }

    public function test_administrator_can_suspend_and_restore_a_user_and_revoke_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $session = $user->authSessions()->create([
            'device_name' => 'Test device', 'last_used_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        app(AuthSessionManager::class)->remember($session);

        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/users/{$user->id}/suspend", [
            'reason' => 'Security review.',
            'suspended_until' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Security review.');

        $this->assertSame(UserStatus::Suspended, $user->fresh()->status);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertFalse(app(AuthSessionManager::class)->isActive($session->id, $user->id));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.suspended', 'actor_id' => $admin->id, 'subject_id' => $user->id,
        ]);

        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/users/{$user->id}/restore", [
            'note' => 'Review completed.',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.restored', 'reason' => 'Review completed.',
        ]);
    }

    public function test_patch_status_requires_reason_and_repeated_transition_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/users/{$user->id}/status", [
            'status' => 'suspended',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $payload = ['status' => 'suspended', 'reason' => 'Repeated request.'];
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/users/{$user->id}/status", $payload)->assertOk();
        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/users/{$user->id}/status", $payload)->assertOk();

        $this->assertSame(1, AuditEvent::query()->where('action', 'user.suspended')->count());
    }

    public function test_suspended_user_cannot_log_in_or_use_authenticated_routes(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/users/{$user->id}/suspend", [
            'reason' => 'Policy violation.',
        ])->assertOk();

        auth('api')->logout();
        $this->postJson('/api/v1/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $this->actingAs($user, 'api')->getJson('/api/v1/me')
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_expired_timed_suspension_is_restored_on_login(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'status' => UserStatus::Suspended,
            'suspended_at' => now()->subDay(),
            'suspended_until' => now()->subMinute(),
            'suspension_reason' => 'Timed hold.',
        ])->save();

        $this->postJson('/api/v1/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk();

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.restored_automatically', 'subject_id' => $user->id,
        ]);
    }

    public function test_staff_can_suspend_customers_but_not_staff_or_administrators(): void
    {
        $staff = User::factory()->staff()->create();
        $statusManager = Role::factory()->create(['name' => 'status_manager']);
        $statusManager->permissions()->attach(
            Permission::query()->where('name', 'customers.update')->firstOrFail(),
        );
        $staff->roles()->attach($statusManager, ['is_primary' => false, 'assigned_at' => now()]);
        $customer = User::factory()->create();
        $otherStaff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($staff, 'api')->postJson("/api/v1/admin/users/{$customer->id}/suspend", [
            'reason' => 'Customer review.',
        ])->assertOk();
        $this->actingAs($staff, 'api')->postJson("/api/v1/admin/users/{$otherStaff->id}/suspend", [
            'reason' => 'Staff review.',
        ])->assertForbidden();
        $this->actingAs($staff, 'api')->postJson("/api/v1/admin/users/{$admin->id}/suspend", [
            'reason' => 'Admin review.',
        ])->assertForbidden();
    }

    public function test_administrator_cannot_suspend_self_but_can_suspend_another_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/users/{$admin->id}/suspend", [
            'reason' => 'Self suspension.',
        ])->assertConflict();
        $this->actingAs($admin, 'api')->postJson("/api/v1/admin/users/{$otherAdmin->id}/suspend", [
            'reason' => 'Administrative review.',
        ])->assertOk();

        $this->assertSame(UserStatus::Active, $admin->fresh()->status);
        $this->assertSame(UserStatus::Suspended, $otherAdmin->fresh()->status);
    }
}
