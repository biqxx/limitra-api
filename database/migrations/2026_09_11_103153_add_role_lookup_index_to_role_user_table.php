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
        Schema::table('role_user', function (Blueprint $table) {
            $table->index(['role_id', 'user_id'], 'role_user_role_user_index');
        });

        DB::statement('CREATE UNIQUE INDEX role_user_one_primary_per_user ON role_user (user_id) WHERE is_primary = true');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS role_user_one_primary_per_user');

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex('role_user_role_user_index');
        });
    }
};
