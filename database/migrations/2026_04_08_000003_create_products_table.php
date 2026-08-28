<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('brand')->nullable()->index();
            $table->string('sku')->nullable()->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('compare_at_price', 15, 2)->nullable();
            $table->char('currency', 3)->default('NGN');
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft')->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_bestseller')->default(false)->index();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->json('images')->nullable();       // legacy URL array; richer uploads use the images table
            $table->json('seo_meta')->nullable();

            // Audit: who created and who has ever edited this product.
            // created_by is nullable so the column can be added safely to future existing rows.
            // updated_by is a JSON array of user IDs (appended, no duplicates) stored in insertion order.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('updated_by')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index('category_id');
            $table->index('subcategory_id');
            $table->index('created_by');
            $table->index(['status', 'created_at']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->json('attributes');
            $table->decimal('price', 15, 2)->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });

        Schema::create('product_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('group')->nullable();
            $table->string('name');
            $table->text('value');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specifications');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
