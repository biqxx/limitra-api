<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $staffRoleId = DB::table('roles')->where('name', 'staff')->value('id');

        if ($staffRoleId === null) {
            return;
        }

        $allowedPermissionIds = DB::table('permissions')
            ->whereIn('name', $this->allowedPermissions())
            ->pluck('id');

        DB::table('permission_role')
            ->where('role_id', $staffRoleId)
            ->whereNotIn('permission_id', $allowedPermissionIds)
            ->delete();
    }

    public function down(): void
    {
        $staffRoleId = DB::table('roles')->where('name', 'staff')->value('id');

        if ($staffRoleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->previousPermissions())
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $staffRoleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    /** @return list<string> */
    private function allowedPermissions(): array
    {
        return [
            'analytics.read',
            'orders.read',
            'refunds.read',
            'returns.read',
            'returns.manage',
            'customers.read',
            'affiliates.read',
            'commissions.read',
            'support.read',
            'support.manage',
            'notifications.read',
            'queue.read',
            'reviews.moderate',
        ];
    }

    /** @return list<string> */
    private function previousPermissions(): array
    {
        return [
            ...$this->allowedPermissions(),
            'catalog.read',
            'inventory.read',
            'inventory.adjust',
            'orders.update',
            'orders.fulfill',
            'orders.export',
            'customers.update',
            'affiliates.update',
            'payouts.read',
            'promotions.read',
            'rewards.read',
            'videos.read',
            'ai.read',
            'cms.read',
            'roles.read',
            'reports.read',
            'audit_logs.read',
            'settings.read',
            'delivery.read',
            'delivery.manage',
        ];
    }
};
