<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('credit_total', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2);
            $table->decimal('total_amount', 14, 2);
            $table->enum('status', ['pending_payment', 'confirmed', 'processing', 'shipped', 'in_transit', 'delivered', 'cancelled'])->default('pending_payment');
            $table->enum('payment_status', ['unpaid', 'pending', 'paid', 'failed', 'refunded', 'partially_refunded'])->default('pending');
            $table->enum('fulfilment_status', ['unfulfilled', 'processing', 'shipped', 'in_transit', 'delivered', 'cancelled'])->default('unfulfilled');
            $table->enum('payment_method', ['card', 'bank_transfer', 'cash_on_delivery']);
            $table->string('contact_email');
            $table->text('notes')->nullable();
            $table->string('delivery_method', 50);
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->foreignId('shipping_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->json('shipping_address');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
