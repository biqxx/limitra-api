<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('country', 2)->default('NG');
            $table->json('states')->nullable();
            $table->json('cities')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['country', 'active', 'priority']);
        });

        Schema::create('delivery_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->enum('type', ['standard', 'express', 'pickup']);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('delivery_zone_method', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_method_id')->constrained()->cascadeOnDelete();
            $table->decimal('fee', 14, 2);
            $table->decimal('free_shipping_threshold', 14, 2)->nullable();
            $table->decimal('minimum_order', 14, 2)->nullable();
            $table->unsignedTinyInteger('estimated_days_min');
            $table->unsignedTinyInteger('estimated_days_max');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['delivery_zone_id', 'delivery_method_id']);
        });

        Schema::create('pickup_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('line1');
            $table->string('city');
            $table->string('state');
            $table->char('country', 2)->default('NG');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['country', 'state', 'city', 'active']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed', 'free_shipping']);
            $table->decimal('value', 14, 2)->default(0);
            $table->decimal('maximum_discount', 14, 2)->nullable();
            $table->decimal('minimum_spend', 14, 2)->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_product', function (Blueprint $table) {
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'product_id']);
        });

        Schema::create('category_promotion', function (Blueprint $table) {
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'category_id']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_amount', 14, 2);
            $table->timestamps();
            $table->index(['promotion_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('category_promotion');
        Schema::dropIfExists('promotion_product');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('pickup_locations');
        Schema::dropIfExists('delivery_zone_method');
        Schema::dropIfExists('delivery_methods');
        Schema::dropIfExists('delivery_zones');
    }
};
