<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('discount_starts_at')->nullable()->after('compare_at_price');
            $table->timestamp('discount_ends_at')->nullable()->after('discount_starts_at');

            $table->index(['discount_starts_at', 'discount_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['discount_starts_at', 'discount_ends_at']);
            $table->dropColumn(['discount_starts_at', 'discount_ends_at']);
        });
    }
};
