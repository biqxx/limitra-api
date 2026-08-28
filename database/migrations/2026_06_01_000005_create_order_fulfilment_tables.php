<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('courier', 100);
            $table->string('tracking_number', 150);
            $table->enum('status', ['shipped', 'in_transit', 'delivered', 'exception'])->default('shipped');
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('shipped_at');
            $table->timestamp('delivered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['courier', 'tracking_number']);
            $table->index(['status', 'estimated_delivery_at']);
        });

        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 50);
            $table->string('description');
            $table->string('location')->nullable();
            $table->timestamp('occurred_at');
            $table->enum('source', ['admin', 'carrier', 'system'])->default('admin');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->string('note')->nullable();
            $table->enum('source', ['customer', 'admin', 'payment', 'system']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('shipments');
    }
};
