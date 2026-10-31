<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();

            // Polymorphic: imageable_id + imageable_type + composite index
            $table->morphs('imageable');

            // Logical type clarifies the role of the image within its owner.
            // user_avatar   → one image per user (enforced at app level)
            // category_image → one image per category (enforced at app level)
            // product_image  → many images per product
            $table->enum('type', ['user_avatar', 'category_image', 'product_image'])->index();

            $table->string('path');             // relative storage path
            $table->string('alt')->nullable();  // accessibility / SEO alt text
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
