<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        $catalog = [
            'staff.read' => ['domain' => 'staff', 'description' => 'View staff and team accounts and invitations.'],
            'staff.manage' => ['domain' => 'staff', 'description' => 'Invite, update roles, suspend, and manage staff accounts.'],
            'referrals.read' => ['domain' => 'referrals', 'description' => 'View referral program activity and analytics.'],
            'referrals.manage' => ['domain' => 'referrals', 'description' => 'Manage referral program rules and rewards.'],
            'celebrity_stores.read' => ['domain' => 'celebrity_stores', 'description' => 'View celebrity storefronts and featured curators.'],
            'celebrity_stores.manage' => ['domain' => 'celebrity_stores', 'description' => 'Create, update, feature, and moderate celebrity stores.'],
            'microstores.read' => ['domain' => 'microstores', 'description' => 'View microstores and analytics.'],
            'microstores.manage' => ['domain' => 'microstores', 'description' => 'Create, update, moderate, and feature microstores.'],
            'procurement.read' => ['domain' => 'procurement', 'description' => 'View supplier orders and procurement status.'],
            'procurement.manage' => ['domain' => 'procurement', 'description' => 'Manage supplier orders, purchasing, and logistics.'],
            'careers.read' => ['domain' => 'careers', 'description' => 'View job listings and candidates.'],
            'careers.manage' => ['domain' => 'careers', 'description' => 'Create jobs and review resumes.'],
            'media.read' => ['domain' => 'media', 'description' => 'Browse media library.'],
            'media.manage' => ['domain' => 'media', 'description' => 'Manage and delete media library items.'],
            'cms.read' => ['domain' => 'cms', 'description' => 'Read CMS and blog content.'],
            'cms.manage' => ['domain' => 'cms', 'description' => 'Create and edit CMS content and blog posts.'],
            'videos.read' => ['domain' => 'videos', 'description' => 'View videos.'],
            'videos.manage' => ['domain' => 'videos', 'description' => 'Manage video catalog and analytics.'],
            'ai.knowledge.manage' => ['domain' => 'ai', 'description' => 'Manage versioned AI knowledge documents.'],
            'ai.settings.manage' => ['domain' => 'ai', 'description' => 'Manage database-backed AI models, prompts and tool configurations.'],
            'mail_logs.read' => ['domain' => 'mail_logs', 'description' => 'View outbound mail logs and previews.'],
            'affiliates.manage' => ['domain' => 'affiliates', 'description' => 'Manage affiliate program rules, tiers, and resources.'],
            'payouts.pay' => ['domain' => 'payouts', 'description' => 'Mark approved payouts as paid.'],
            'reports.retry' => ['domain' => 'reports', 'description' => 'Retry failed reports.'],
        ];

        foreach ($catalog as $name => $meta) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'domain' => $meta['domain'],
                    'description' => $meta['description'],
                    'is_system' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        // Ensure the system admin role holds every permission so strict permission checks work seamlessly.
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminRoleId && Schema::hasTable('permission_role')) {
            $allPermissionIds = DB::table('permissions')->pluck('id');
            foreach ($allPermissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $adminRoleId,
                        'permission_id' => $permissionId,
                    ],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->whereIn('name', [
            'staff.read',
            'staff.manage',
            'referrals.read',
            'referrals.manage',
            'celebrity_stores.read',
            'celebrity_stores.manage',
        ])->delete();
    }
};
