<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        Route::middleware(['auth:api', 'permission:analytics.read'])
            ->get('/api/_test/analytics', fn () => response()->json(['allowed' => true]));

        Route::middleware(['auth:api', 'permission:analytics.read,reports.download'])
            ->get('/api/_test/reports', fn () => response()->json(['allowed' => true]));
    }

    public function test_system_role_permissions_and_admin_wildcard_are_enforced(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/_test/analytics')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'api')
            ->getJson('/api/_test/analytics')
            ->assertOk();

        $this->actingAs($staff, 'api')
            ->getJson('/api/v1/admin/settings')
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/_test/reports')
            ->assertOk();
    }

    public function test_custom_role_permissions_are_database_authoritative_between_requests(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => 'analyst']);
        $permission = Permission::query()->where('name', 'analytics.read')->firstOrFail();
        $role->permissions()->attach($permission);
        $user->roles()->attach($role, [
            'is_primary' => false,
            'assigned_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/_test/analytics')
            ->assertOk();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/analytics/overview')
            ->assertOk();

        $role->permissions()->detach($permission);

        $this->actingAs($user, 'api')
            ->getJson('/api/_test/analytics')
            ->assertForbidden();
    }

    public function test_every_permission_listed_on_the_middleware_is_required(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/_test/reports')
            ->assertForbidden();

        $staff = Role::query()->where('name', 'staff')->firstOrFail();
        $download = Permission::query()->where('name', 'reports.download')->firstOrFail();
        $staff->permissions()->attach($download);

        $this->actingAs($user, 'api')
            ->getJson('/api/_test/reports')
            ->assertOk();
    }
}
