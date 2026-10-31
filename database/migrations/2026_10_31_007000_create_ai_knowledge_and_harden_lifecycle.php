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
        // 1. Create AI Knowledge Documents table
        if (! Schema::hasTable('ai_knowledge_documents')) {
            Schema::create('ai_knowledge_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('category', 100)->default('general')->index();
                $table->unsignedInteger('version')->default(1);
                $table->longText('content');
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedInteger('token_count')->default(0);
                $table->timestamp('indexed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        // 2. Add latency and token tracking to conversation messages
        Schema::table('conversation_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversation_messages', 'latency_ms')) {
                $table->unsignedInteger('latency_ms')->nullable();
            }
            if (! Schema::hasColumn('conversation_messages', 'tokens')) {
                $table->json('tokens')->nullable();
            }
        });

        // 3. Seed AI administration permissions if missing
        if (Schema::hasTable('permissions')) {
            $now = now();
            $newPerms = [
                'ai.knowledge.manage' => 'Manage versioned AI knowledge documents.',
                'ai.settings.manage' => 'Manage database-backed AI models, prompts and tool configurations.',
            ];

            foreach ($newPerms as $permName => $desc) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $permName,
                    'domain' => 'ai',
                    'description' => $desc,
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $roleIds = DB::table('roles')->whereIn('name', ['admin', 'staff'])->pluck('id');
                $permIds = DB::table('permissions')->whereIn('name', array_keys($newPerms))->pluck('id');

                foreach ($roleIds as $roleId) {
                    foreach ($permIds as $permId) {
                        DB::table('permission_role')->insertOrIgnore([
                            'role_id' => $roleId,
                            'permission_id' => $permId,
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
        Schema::dropIfExists('ai_knowledge_documents');

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropColumn(['latency_ms', 'tokens']);
        });

        if (Schema::hasTable('permissions')) {
            $permIds = DB::table('permissions')->whereIn('name', ['ai.knowledge.manage', 'ai.settings.manage'])->pluck('id');
            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $permIds)->delete();
            }
            DB::table('permissions')->whereIn('name', ['ai.knowledge.manage', 'ai.settings.manage'])->delete();
        }
    }
};
