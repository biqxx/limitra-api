<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('roles')->upsert(
            collect($this->roles())->map(fn (array $role, string $name): array => [
                'name' => $name,
                ...$role,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['display_name', 'description', 'is_system', 'is_super_admin', 'updated_at'],
        );

        DB::table('permissions')->upsert(
            collect($this->permissions())->map(fn (string $domain, string $name): array => [
                'name' => $name,
                'domain' => $domain,
                'description' => $this->permissionDescription($name),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
            ['name'],
            ['domain', 'description', 'is_system', 'updated_at'],
        );

        $roleIds = DB::table('roles')->whereIn('name', array_keys($this->roles()))->pluck('id', 'name');
        $permissionIds = DB::table('permissions')->pluck('id', 'name');

        foreach ($this->rolePermissions() as $roleName => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleIds[$roleName],
                    'permission_id' => $permissionIds[$permissionName],
                ]);
            }
        }

        DB::table('users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($now, $roleIds): void {
                foreach ($users as $user) {
                    if (! isset($roleIds[$user->role])) {
                        continue;
                    }

                    DB::table('role_user')->insertOrIgnore([
                        'user_id' => $user->id,
                        'role_id' => $roleIds[$user->role],
                        'assigned_by' => null,
                        'is_primary' => true,
                        'assigned_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('roles')->where('is_system', true)->delete();
        DB::table('permissions')->where('is_system', true)->delete();
    }

    /**
     * @return array<string, array{display_name: string, description: string, is_super_admin: bool}>
     */
    private function roles(): array
    {
        return [
            'user' => [
                'display_name' => 'Customer',
                'description' => 'Default customer access.',
                'is_super_admin' => false,
            ],
            'affiliate' => [
                'display_name' => 'Affiliate',
                'description' => 'Customer access plus affiliate tools.',
                'is_super_admin' => false,
            ],
            'staff' => [
                'display_name' => 'Staff',
                'description' => 'Operational staff access.',
                'is_super_admin' => false,
            ],
            'admin' => [
                'display_name' => 'Administrator',
                'description' => 'Protected super-administrator access.',
                'is_super_admin' => true,
            ],
        ];
    }

    /** @return array<string, string> */
    private function permissions(): array
    {
        return [
            'account.manage' => 'account',
            'cart.manage' => 'cart',
            'orders.manage' => 'orders',
            'affiliate.dashboard' => 'affiliates',
            'affiliate.links' => 'affiliates',
            'affiliate.payouts' => 'payouts',
            'analytics.read' => 'analytics',
            'analytics.manage' => 'analytics',
            'catalog.read' => 'catalog',
            'catalog.create' => 'catalog',
            'catalog.update' => 'catalog',
            'catalog.archive' => 'catalog',
            'inventory.read' => 'inventory',
            'inventory.adjust' => 'inventory',
            'orders.read' => 'orders',
            'orders.update' => 'orders',
            'orders.fulfill' => 'orders',
            'orders.refund' => 'orders',
            'orders.export' => 'orders',
            'refunds.read' => 'refunds',
            'refunds.resolve' => 'refunds',
            'returns.read' => 'returns',
            'returns.manage' => 'returns',
            'returns.refund' => 'returns',
            'customers.read' => 'customers',
            'customers.update' => 'customers',
            'affiliates.read' => 'affiliates',
            'affiliates.update' => 'affiliates',
            'affiliates.approve' => 'affiliates',
            'commissions.read' => 'commissions',
            'commissions.update' => 'commissions',
            'payouts.read' => 'payouts',
            'payouts.approve' => 'payouts',
            'promotions.read' => 'promotions',
            'promotions.manage' => 'promotions',
            'rewards.read' => 'rewards',
            'rewards.manage' => 'rewards',
            'videos.read' => 'videos',
            'videos.manage' => 'videos',
            'ai.read' => 'ai',
            'ai.manage' => 'ai',
            'support.read' => 'support',
            'support.manage' => 'support',
            'cms.read' => 'cms',
            'cms.manage' => 'cms',
            'cms.publish' => 'cms',
            'roles.read' => 'roles',
            'roles.manage' => 'roles',
            'reports.read' => 'reports',
            'reports.create' => 'reports',
            'reports.download' => 'reports',
            'audit_logs.read' => 'audit_logs',
            'settings.read' => 'settings',
            'settings.manage' => 'settings',
            'notifications.read' => 'notifications',
            'notifications.manage' => 'notifications',
            'queue.read' => 'queue',
            'queue.retry' => 'queue',
            'reviews.moderate' => 'reviews',
            'delivery.read' => 'delivery',
            'delivery.manage' => 'delivery',
        ];
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        $customer = ['account.manage', 'orders.manage', 'cart.manage'];

        return [
            'user' => $customer,
            'affiliate' => [...$customer, 'affiliate.dashboard', 'affiliate.links', 'affiliate.payouts'],
            'staff' => [
                'analytics.read', 'catalog.read', 'inventory.read', 'inventory.adjust',
                'orders.read', 'orders.update', 'orders.fulfill', 'orders.export',
                'refunds.read', 'returns.read', 'returns.manage',
                'customers.read', 'customers.update',
                'affiliates.read', 'affiliates.update', 'commissions.read', 'payouts.read',
                'promotions.read', 'rewards.read', 'videos.read', 'ai.read',
                'support.read', 'support.manage', 'cms.read', 'roles.read',
                'reports.read', 'audit_logs.read', 'settings.read', 'notifications.read',
                'queue.read', 'reviews.moderate', 'delivery.read', 'delivery.manage',
            ],
            'admin' => array_keys($this->permissions()),
        ];
    }

    private function permissionDescription(string $permission): string
    {
        return ucfirst(str_replace(['.', '_'], [' ', ' '], $permission)).'.';
    }
};
