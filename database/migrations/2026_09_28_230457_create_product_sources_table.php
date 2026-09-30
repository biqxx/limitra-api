<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('supplier', 40);
            $table->string('external_product_id');
            $table->text('supplier_url')->nullable();
            $table->decimal('supplier_price', 15, 4)->nullable();
            $table->char('supplier_currency', 3)->nullable();
            $table->boolean('available')->default(false);
            $table->decimal('weight_lb', 12, 4)->nullable();
            $table->unsignedSmallInteger('delivery_days_min')->nullable();
            $table->unsignedSmallInteger('delivery_days_max')->nullable();
            $table->string('restriction_status', 40)->nullable();
            $table->string('validation_status', 40)->nullable();
            $table->decimal('final_price_ngn', 15, 2)->nullable();
            $table->json('variants')->nullable();
            $table->json('cost_breakdown')->nullable();
            $table->json('source_snapshot');
            $table->string('sync_status', 30)->default('imported');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier', 'external_product_id']);
            $table->index(['product_id', 'supplier']);
            $table->index(['sync_status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sources');
    }
};
