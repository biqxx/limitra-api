<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->enum('status', ['pending', 'published', 'rejected'])->default('pending');
            $table->boolean('verified_purchase')->default(true);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_reason')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at']);
            $table->index(['product_id', 'status', 'rating']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('review_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['review_id', 'sort_order']);
        });

        Schema::create('review_helpful_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['review_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_helpful_votes');
        Schema::dropIfExists('review_images');
        Schema::dropIfExists('reviews');
    }
};
