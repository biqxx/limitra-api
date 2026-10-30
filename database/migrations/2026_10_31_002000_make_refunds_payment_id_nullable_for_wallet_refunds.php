<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignId('payment_id')->nullable()->change();
            $table->string('method', 50)->default('original_payment')->change();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignId('payment_id')->nullable(false)->change();
        });
    }
};
