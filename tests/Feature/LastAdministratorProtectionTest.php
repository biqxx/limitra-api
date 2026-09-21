<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\UserAccessController;
use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LastAdministratorProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_guarded_routes_replace_the_legacy_role_and_delete_handlers(): void
    {
        $updateRoute = collect(Route::getRoutes())->last(
            fn ($route) => $route->uri() === 'api/v1/admin/users/{user}/role',
        );
        $deleteRoute = collect(Route::getRoutes())->last(
            fn ($route) => $route->uri() === 'api/v1/admin/users/{user}'
                && in_array('DELETE', $route->methods(), true),
        );

        $this->assertSame(UserAccessController::class.'@updateRole', $updateRoute?->getActionName());
        $this->assertSame(UserAccessController::class.'@destroy', $deleteRoute?->getActionName());
    }

    public function test_last_administrator_cannot_be_demoted_or_deleted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')->patchJson("/api/v1/admin/users/{$admin->id}/role", [
            'role' => 'staff',
        ])->assertConflict()->assertJsonPath('code', 'CONFLICT');
        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/users/{$admin->id}", [
            'confirmation' => true,
            'reason' => 'Administrative cleanup.',
        ])
            ->assertConflict();

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_one_administrator_can_demote_and_delete_another_when_admins_remain(): void
    {
        $actor = User::factory()->admin()->create();
        $demotedAdmin = User::factory()->admin()->create();

        $this->actingAs($actor, 'api')->patchJson("/api/v1/admin/users/{$demotedAdmin->id}/role", [
            'role' => 'staff',
        ])->assertOk()->assertJsonPath('data.role', 'staff');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.role_changed', 'subject_id' => $demotedAdmin->id,
        ]);

        $deletedAdmin = User::factory()->admin()->create();
        $deletedAdminId = $deletedAdmin->id;
        $this->actingAs($actor, 'api')->deleteJson("/api/v1/admin/users/{$deletedAdminId}", [
            'confirmation' => true,
            'reason' => 'Administrator left the organization.',
        ])
            ->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $deletedAdminId]);
        $this->assertSame('deactivated', User::withTrashed()->findOrFail($deletedAdminId)->status->value);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.deactivated', 'subject_id' => $deletedAdminId,
        ]);
    }

    public function test_non_super_admin_cannot_change_legacy_roles_or_delete_users(): void
    {
        $staff = User::factory()->staff()->create();
        $target = User::factory()->create();

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/users/{$target->id}/role", [
            'role' => 'affiliate',
        ])->assertForbidden();
        $this->actingAs($staff, 'api')->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertForbidden();
    }
}
