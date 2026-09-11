<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentUserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
    }

    public function test_me_returns_database_backed_effective_permissions_and_role_summary(): void
    {
        $this->withoutMiddleware([EnsureActiveSession::class, TrackAnalytics::class]);

        $user = User::factory()->create();
        $permission = Permission::factory()->create([
            'name' => 'support.escalate',
            'domain' => 'support',
        ]);
        $role = Role::factory()->create([
            'name' => 'customer_support',
            'display_name' => 'Customer Support',
        ]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role, [
            'is_primary' => false,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/v1/me');

        $response
            ->assertOk()
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.linked_roles.affiliate', false)
            ->assertJsonPath('data.linked_roles.staff', false)
            ->assertJsonFragment([
                'name' => 'customer_support',
                'display_name' => 'Customer Support',
                'is_primary' => false,
            ]);

        $this->assertSame(
            ['account.manage', 'cart.manage', 'orders.manage', 'support.escalate'],
            $response->json('data.permissions'),
        );
    }

    public function test_me_returns_wildcard_permission_and_staff_link_for_admin(): void
    {
        $this->withoutMiddleware([EnsureActiveSession::class, TrackAnalytics::class]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', ['*'])
            ->assertJsonPath('data.linked_roles.staff', true)
            ->assertJsonFragment([
                'name' => 'admin',
                'display_name' => 'Administrator',
                'is_primary' => true,
            ]);
    }
}
