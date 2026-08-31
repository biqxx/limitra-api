<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_hourly_aggregates', function (Blueprint $table) {
            $table->id();
            $table->timestamp('hour_at')->unique();
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('product_views')->default(0);
            $table->unsignedInteger('add_to_carts')->default(0);
            $table->unsignedInteger('checkouts_started')->default(0);
            $table->unsignedInteger('checkouts_completed')->default(0);
            $table->unsignedInteger('orders_placed')->default(0);
            $table->decimal('orders_revenue', 14, 2)->default(0);
            $table->unsignedInteger('new_users')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_hourly_aggregates');
    }
};
