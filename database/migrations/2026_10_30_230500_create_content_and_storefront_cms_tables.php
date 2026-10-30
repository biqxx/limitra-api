<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title', 255);
            $table->string('channel', 30)->default('web')->index();
            $table->string('locale', 10)->default('en');
            $table->longText('body')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('title', 255);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('cover_image_url')->nullable();
            $table->json('tags')->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('title', 255);
            $table->string('url', 500);
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->boolean('is_featured')->default(false)->index();
            $table->json('tagged_product_ids')->nullable();
            $table->string('status', 30)->default('published')->index();
            $table->timestamps();
        });

        Schema::create('newsletter_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('source', 50)->default('footer');
            $table->string('status', 30)->default('subscribed')->index();
            $table->string('token', 64)->unique();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('career_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('title', 255);
            $table->string('department', 100)->nullable()->index();
            $table->string('location', 100)->default('Remote');
            $table->string('type', 50)->default('full-time');
            $table->longText('description');
            $table->text('requirements')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('career_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('career_job_id')->nullable()->constrained('career_jobs')->nullOnDelete();
            $table->string('name', 150);
            $table->string('email', 150)->index();
            $table->string('phone', 50)->nullable();
            $table->string('cv_url', 500);
            $table->text('cover_letter')->nullable();
            $table->string('portfolio_url', 500)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('career_applications');
        Schema::dropIfExists('career_jobs');
        Schema::dropIfExists('newsletter_subscriptions');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('content_entries');
    }
};
