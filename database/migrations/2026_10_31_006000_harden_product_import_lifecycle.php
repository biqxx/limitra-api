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
        Schema::table('product_import_jobs', function (Blueprint $table): void {
            $table->string('file_path')->nullable();
            $table->string('file_format', 10)->default('csv');
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('error_report_path')->nullable();
            $table->string('duplicate_sku_policy', 30)->default('update');
            $table->string('duplicate_slug_policy', 30)->default('generate_suffix');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('idempotency_key', 100)->nullable()->index();
            $table->string('request_hash', 64)->nullable();
            $table->unsignedInteger('current_chunk')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('retry_count')->default(0);

            $table->index(['user_id', 'idempotency_key']);
        });

        if (Schema::hasTable('permissions')) {
            $now = now();
            DB::table('permissions')->insertOrIgnore([
                'name' => 'catalog.import',
                'domain' => 'catalog',
                'description' => 'Import catalogue products and manage import jobs.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $permissionId = DB::table('permissions')->where('name', 'catalog.import')->value('id');
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
        Schema::table('product_import_jobs', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'idempotency_key']);
            $table->dropColumn([
                'file_path',
                'file_format',
                'file_size_bytes',
                'error_report_path',
                'duplicate_sku_policy',
                'duplicate_slug_policy',
                'started_at',
                'completed_at',
                'cancelled_at',
                'idempotency_key',
                'request_hash',
                'current_chunk',
                'total_chunks',
                'retry_count',
            ]);
        });

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('name', 'catalog.import')->value('id');
            if ($permissionId && Schema::hasTable('permission_role')) {
                DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('name', 'catalog.import')->delete();
        }
    }
};
