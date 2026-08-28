<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('quote_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->restrictOnDelete();
            $table->foreignId('address_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('saved_card_id')->nullable()->constrained('saved_cards')->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('payment_method', ['card', 'bank_transfer', 'cash_on_delivery']);
            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('wallet_credit', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            $table->json('address_snapshot');
            $table->json('shipping_snapshot');
            $table->json('promotion_snapshot')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at', 'consumed_at']);
        });

        Schema::create('checkout_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->json('selected_options')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_quote_items');
        Schema::dropIfExists('checkout_quotes');
    }
};
