<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // This migration MUST run after 2026_04_11_121554_create_affiliates_table.
    // The timestamp ordering guarantees this when all migrations are fresh.

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Which affiliate recruited this user (via referral link at signup).
            // Nullable so existing users and users who signed up organically have NULL.
            $table->foreignId('referred_by')
                ->nullable()
                ->after('role')
                ->constrained('affiliates')
                ->nullOnDelete();

            $table->index('referred_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropIndex(['referred_by']);
            $table->dropColumn('referred_by');
        });
    }
};
