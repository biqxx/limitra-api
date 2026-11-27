<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('guest_token_hash', 64)->nullable()->after('user_id');
            $table->index('guest_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['guest_token_hash']);
            $table->dropColumn('guest_token_hash');
        });
    }
};
