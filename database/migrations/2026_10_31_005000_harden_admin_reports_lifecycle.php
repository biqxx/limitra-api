<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admin_reports', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->index();
            $table->string('request_hash', 64)->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->json('columns')->nullable();
            $table->json('filters')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);

            $table->index(['user_id', 'idempotency_key']);
        });

        // Seed purpose-specific reports.retry permission if access control tables exist
        if (Schema::hasTable('permissions')) {
            $now = now();
            DB::table('permissions')->insertOrIgnore([
                'name' => 'reports.retry',
                'domain' => 'reports',
                'description' => 'Retry failed reports.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $permissionId = DB::table('permissions')->where('name', 'reports.retry')->value('id');
                $roleIds = DB::table('roles')->whereIn('name', ['admin', 'staff'])->pluck('id');

                if ($permissionId) {
                    foreach ($roleIds as $roleId) {
                        DB::table('permission_role')->insertOrIgnore([
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_reports', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'idempotency_key']);
            $table->dropColumn([
                'idempotency_key',
                'request_hash',
                'timezone',
                'columns',
                'filters',
                'file_size_bytes',
                'started_at',
                'failed_at',
                'expires_at',
                'error_message',
                'retry_count',
            ]);
        });

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('name', 'reports.retry')->value('id');
            if ($permissionId && Schema::hasTable('permission_role')) {
                DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('name', 'reports.retry')->delete();
        }
    }
};
