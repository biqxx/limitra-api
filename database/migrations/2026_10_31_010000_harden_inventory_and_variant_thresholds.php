<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_variants') && ! Schema::hasColumn('product_variants', 'low_stock_threshold')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('low_stock_threshold')->default(5)->after('stock');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'low_stock_threshold')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->dropColumn('low_stock_threshold');
            });
        }
    }
};
