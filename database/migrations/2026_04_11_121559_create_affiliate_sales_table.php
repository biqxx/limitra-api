<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per order-item that qualifies for affiliate credit.
        // Direct  → buyer used the affiliate's tracking link (?ref=CODE) on this purchase.
        // Indirect → buyer was referred by the affiliate (users.referred_by) and purchased independently.
        Schema::create('affiliate_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('sale_type', ['direct', 'indirect'])->index();
            $table->decimal('sale_amount', 10, 2);  // price_at_purchase × quantity
            $table->timestamps();

            $table->index('affiliate_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_sales');
    }
};
