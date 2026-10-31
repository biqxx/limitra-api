<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('content_entries') && ! Schema::hasColumn('content_entries', 'scheduled_at')) {
            Schema::table('content_entries', function (Blueprint $table): void {
                $table->timestamp('scheduled_at')->nullable()->after('status')->index();
            });
        }

        if (Schema::hasTable('blog_posts') && ! Schema::hasColumn('blog_posts', 'scheduled_at')) {
            Schema::table('blog_posts', function (Blueprint $table): void {
                $table->timestamp('scheduled_at')->nullable()->after('status')->index();
            });
        }

        if (Schema::hasTable('career_applications') && ! Schema::hasColumn('career_applications', 'cv_path')) {
            Schema::table('career_applications', function (Blueprint $table): void {
                $table->string('cv_path', 500)->nullable()->after('cv_url');
            });
        }

        if (! Schema::hasTable('content_revisions')) {
            Schema::create('content_revisions', function (Blueprint $table): void {
                $table->id();
                $table->string('revisionable_type', 100);
                $table->unsignedBigInteger('revisionable_id');
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title', 255);
                $table->longText('body')->nullable();
                $table->string('summary', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['revisionable_type', 'revisionable_id']);
            });
        }

        if (! Schema::hasTable('media_files')) {
            Schema::create('media_files', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('disk', 50)->default('public');
                $table->string('filename', 255);
                $table->string('original_name', 255);
                $table->string('path', 500);
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('size_bytes');
                $table->string('title', 255)->nullable();
                $table->string('alt', 255)->nullable();
                $table->string('folder', 100)->default('uploads')->index();
                $table->json('tags')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('video_events')) {
            Schema::create('video_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 50)->index();
                $table->unsignedInteger('duration_watched')->default(0);
                $table->string('session_id', 100)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['video_id', 'event_type']);
            });
        }

        // Seed access control permissions for CMS, Media, Careers, and Videos if missing
        $permissions = [
            'careers.read' => ['domain' => 'careers', 'description' => 'View job listings and candidates.'],
            'careers.manage' => ['domain' => 'careers', 'description' => 'Create jobs and review resumes.'],
            'media.read' => ['domain' => 'media', 'description' => 'Browse media library.'],
            'media.upload' => ['domain' => 'media', 'description' => 'Upload media files.'],
            'media.manage' => ['domain' => 'media', 'description' => 'Manage and delete media library items.'],
            'cms.read' => ['domain' => 'cms', 'description' => 'Read CMS and blog content.'],
            'cms.manage' => ['domain' => 'cms', 'description' => 'Create and edit CMS content and blog posts.'],
            'cms.publish' => ['domain' => 'cms', 'description' => 'Publish and restore revisions.'],
            'videos.read' => ['domain' => 'videos', 'description' => 'View videos.'],
            'videos.manage' => ['domain' => 'videos', 'description' => 'Manage video catalog and analytics.'],
        ];

        $now = now();
        foreach ($permissions as $name => $data) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'domain' => $data['domain'],
                'description' => $data['description'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_events');
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('content_revisions');

        if (Schema::hasTable('career_applications') && Schema::hasColumn('career_applications', 'cv_path')) {
            Schema::table('career_applications', function (Blueprint $table): void {
                $table->dropColumn('cv_path');
            });
        }

        if (Schema::hasTable('blog_posts') && Schema::hasColumn('blog_posts', 'scheduled_at')) {
            Schema::table('blog_posts', function (Blueprint $table): void {
                $table->dropColumn('scheduled_at');
            });
        }

        if (Schema::hasTable('content_entries') && Schema::hasColumn('content_entries', 'scheduled_at')) {
            Schema::table('content_entries', function (Blueprint $table): void {
                $table->dropColumn('scheduled_at');
            });
        }
    }
};
