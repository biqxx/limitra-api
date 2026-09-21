<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use App\Services\Auth\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_roles_and_permission_domains_are_seeded_by_migrations(): void
    {
        $this->assertSame(
            ['admin', 'affiliate', 'staff', 'user'],
            Role::query()->where('is_system', true)->orderBy('name')->pluck('name')->all(),
        );

        $this->assertTrue(Role::query()->where('name', 'admin')->value('is_super_admin'));

        $requiredDomains = [
            'ai', 'affiliates', 'analytics', 'audit_logs', 'catalog', 'cms',
            'commissions', 'customers', 'inventory', 'orders', 'payouts',
            'promotions', 'refunds', 'reports', 'rewards', 'roles', 'support', 'videos',
        ];

        $seededDomains = Permission::query()->distinct()->pluck('domain')->all();

        foreach ($requiredDomains as $domain) {
            $this->assertContains($domain, $seededDomains);
        }
    }

    public function test_new_users_receive_their_legacy_role_as_primary_membership(): void
    {
        $affiliate = User::factory()->affiliate()->create();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $affiliate->id,
            'role_id' => Role::query()->where('name', 'affiliate')->value('id'),
            'is_primary' => true,
        ]);

        $this->assertSame(
            ['account.manage', 'affiliate.dashboard', 'affiliate.links', 'affiliate.payouts', 'cart.manage', 'orders.manage'],
            app(PermissionResolver::class)->resolve($affiliate),
        );
    }

    public function test_changing_a_legacy_role_replaces_only_the_system_membership(): void
    {
        $user = User::factory()->create();
        $customRole = Role::factory()->create(['name' => 'customer_support']);
        $user->roles()->attach($customRole, [
            'is_primary' => false,
            'assigned_at' => now(),
        ]);

        $user->update(['role' => 'staff']);

        $this->assertSame(
            ['customer_support', 'staff'],
            $user->fresh()->roles()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => Role::query()->where('name', 'staff')->value('id'),
            'is_primary' => true,
        ]);
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $user->id,
            'role_id' => Role::query()->where('name', 'user')->value('id'),
        ]);
    }

    public function test_effective_permissions_merge_all_assigned_roles_and_admin_is_super_admin(): void
    {
        $user = User::factory()->create();
        $permission = Permission::factory()->create([
            'name' => 'support.escalate',
            'domain' => 'support',
        ]);
        $role = Role::factory()->create(['name' => 'support_specialist']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role, [
            'is_primary' => false,
            'assigned_at' => now(),
        ]);

        $permissions = app(PermissionResolver::class)->resolve($user->fresh());

        $this->assertContains('account.manage', $permissions);
        $this->assertContains('support.escalate', $permissions);
        $this->assertSame(['*'], app(PermissionResolver::class)->resolve(User::factory()->admin()->create()));
    }
}
