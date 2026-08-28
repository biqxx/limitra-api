<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->json('selected_options')->nullable();
            $table->char('line_key', 64);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price_at_addition', 14, 2);
            $table->timestamps();

            $table->unique(['cart_id', 'line_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
