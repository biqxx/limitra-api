<?php

namespace Tests\Feature\Content;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Content\BlogPost;
use App\Models\Content\CareerApplication;
use App\Models\Content\CareerJob;
use App\Models\Content\ContentEntry;
use App\Models\Content\NewsletterSubscription;
use App\Models\Content\Video;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CMSAndStorefrontLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Storage::fake('public');
        Storage::fake('private');
        Storage::fake('local');
    }

    public function test_scheduled_content_and_blog_publishing_command(): void
    {
        // Content entry scheduled in the future
        $futureEntry = ContentEntry::create([
            'title' => 'Future Announcement',
            'slug' => 'future-announcement',
            'body' => 'Coming soon in 2027.',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(5),
            'published_at' => now()->addDays(5),
        ]);

        // Content entry scheduled in the past (ready to publish)
        $readyEntry = ContentEntry::create([
            'title' => 'Ready Entry',
            'slug' => 'ready-entry',
            'body' => 'Ready to go live!',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(10),
            'published_at' => now()->subMinutes(10),
        ]);

        // Blog post scheduled in the past
        $readyPost = BlogPost::create([
            'title' => 'Scheduled Blog Post',
            'slug' => 'scheduled-blog-post',
            'content' => 'Deep dive into e-commerce architecture.',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
            'published_at' => now()->subMinutes(5),
        ]);

        // Future entry is NOT accessible publicly
        $this->getJson('/api/v1/content/future-announcement')
            ->assertNotFound();

        // Ready entry not yet published before command runs
        $this->getJson('/api/v1/content/ready-entry')
            ->assertNotFound();

        // Run publish command
        $exitCode = Artisan::call('content:publish-scheduled');
        $this->assertSame(0, $exitCode);

        // Ready entry and post are now published
        $this->assertSame('published', $readyEntry->fresh()->status);
        $this->assertSame('published', $readyPost->fresh()->status);

        // Future entry remains scheduled
        $this->assertSame('scheduled', $futureEntry->fresh()->status);

        // Now accessible publicly
        $this->getJson('/api/v1/content/ready-entry')
            ->assertOk()
            ->assertJsonPath('data.title', 'Ready Entry');

        $this->getJson('/api/v1/blog/posts/scheduled-blog-post')
            ->assertOk()
            ->assertJsonPath('data.title', 'Scheduled Blog Post');
    }

    public function test_cms_and_blog_revisions_and_restore(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Create blog post as admin
        $createRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/blog/posts', [
                'title' => 'Version 1 Post',
                'slug' => 'v1-post',
                'content' => 'Initial content version 1',
                'category' => 'Tech',
                'status' => 'draft',
            ]);

        $createRes->assertCreated();
        $postId = $createRes->json('data.id');
        $post = BlogPost::findOrFail($postId);

        // Verify initial revision was automatically recorded
        $this->assertCount(1, $post->revisions);
        $this->assertSame('Initial version', $post->revisions->first()->summary);

        // Update blog post
        $updateRes = $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/blog/posts/{$postId}", [
                'title' => 'Version 2 Post',
                'content' => 'Updated content version 2',
                'change_summary' => 'Rewrote intro',
            ]);

        $updateRes->assertOk();
        $this->assertCount(2, $post->fresh()->revisions);

        // List revisions endpoint
        $listRes = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/blog/posts/{$postId}/revisions");

        $listRes->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $initialRevision = $post->revisions()->where('title', 'Version 1 Post')->first();
        $this->assertNotNull($initialRevision);

        // Restore version 1
        $restoreRes = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/blog/posts/{$postId}/revisions/{$initialRevision->id}/restore");

        $restoreRes->assertOk();
        $this->assertSame('Version 1 Post', $post->fresh()->title);
        $this->assertSame('Initial content version 1', $post->fresh()->content);

        // Content entry revisions
        $entryRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/content', [
                'title' => 'Privacy Policy V1',
                'slug' => 'privacy-v1',
                'body' => 'Old privacy terms',
                'status' => 'draft',
            ]);

        $entryId = $entryRes->json('data.id');
        $entry = ContentEntry::findOrFail($entryId);

        $this->actingAs($admin, 'api')
            ->putJson("/api/v1/admin/content/{$entryId}", [
                'title' => 'Privacy Policy V2',
                'body' => 'New privacy terms',
            ])->assertOk();

        $entryRevisionsRes = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/content/{$entryId}/revisions");

        $entryRevisionsRes->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $v1Revision = $entry->revisions()->where('title', 'Privacy Policy V1')->first();
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/content/{$entryId}/revisions/{$v1Revision->id}/restore")
            ->assertOk();

        $this->assertSame('Privacy Policy V1', $entry->fresh()->title);
    }

    public function test_media_library_upload_filter_and_deletion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->image('banner_hero.jpg', 800, 600);

        // Upload media file
        $uploadRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/media', [
                'file' => $file,
                'title' => 'Black Friday Banner',
                'alt' => '50% off storewide',
                'folder' => 'banners',
                'tags' => ['promo', 'black-friday'],
            ]);

        $uploadRes->assertCreated()
            ->assertJsonPath('data.title', 'Black Friday Banner')
            ->assertJsonPath('data.folder', 'banners')
            ->assertJsonPath('data.mime_type', 'image/jpeg');

        $mediaId = $uploadRes->json('data.id');
        $this->assertDatabaseHas('media_files', [
            'id' => $mediaId,
            'folder' => 'banners',
        ]);

        // Filter media files
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/media?folder=banners')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.title', 'Black Friday Banner');

        // Update media metadata
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/media/{$mediaId}", [
                'title' => 'Updated Banner Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Banner Name');

        // Delete media
        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/media/{$mediaId}")
            ->assertOk();

        $this->assertDatabaseMissing('media_files', ['id' => $mediaId]);
    }

    public function test_career_cv_private_upload_and_authorized_download(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'user']);

        $job = CareerJob::create([
            'title' => 'Lead DevOps Architect',
            'slug' => 'lead-devops-architect',
            'department' => 'Infrastructure',
            'description' => 'Architect AWS and Kubernetes clusters.',
            'status' => 'open',
        ]);

        $cvFile = UploadedFile::fake()->create('alex_resume.pdf', 500, 'application/pdf');

        // Candidate submits application with private CV file
        $applyRes = $this->postJson("/api/v1/jobs/{$job->id}/applications", [
            'name' => 'Alex Architect',
            'email' => 'alex@example.test',
            'cv_file' => $cvFile,
            'cover_letter' => 'Here is my CV.',
        ]);

        $applyRes->assertCreated()
            ->assertJsonPath('data.name', 'Alex Architect');

        $applicationId = $applyRes->json('data.id');
        $application = CareerApplication::findOrFail($applicationId);
        $this->assertNotNull($application->cv_path);

        // Guest cannot download CV
        $this->getJson("/api/v1/admin/job-applications/{$applicationId}/cv")
            ->assertUnauthorized();

        // Customer cannot download CV
        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/admin/job-applications/{$applicationId}/cv")
            ->assertForbidden();

        // Admin can download CV
        $downloadRes = $this->actingAs($admin, 'api')
            ->get("/api/v1/admin/job-applications/{$applicationId}/cv");

        $downloadRes->assertOk();
    }

    public function test_newsletter_double_optin_token_verification(): void
    {
        $subscription = NewsletterSubscription::create([
            'email' => 'subscriber2@example.test',
            'token' => 'secure-double-opt-in-token-12345',
            'status' => 'subscribed',
            'consented_at' => now(),
        ]);

        // Invalid token returns 404
        $this->getJson('/api/v1/newsletter/unsubscribe/verify?token=invalid-token')
            ->assertNotFound();

        // Valid token successfully unsubscribes
        $this->getJson('/api/v1/newsletter/unsubscribe/verify?token=secure-double-opt-in-token-12345')
            ->assertOk()
            ->assertJsonPath('data.status', 'unsubscribed');

        $this->assertSame('unsubscribed', $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->unsubscribed_at);
    }

    public function test_video_batched_analytics_event_ingest(): void
    {
        $video = Video::create([
            'title' => 'Limitra Brand Launch',
            'slug' => 'limitra-brand-launch',
            'url' => 'https://vimeo.com/987654321',
            'status' => 'published',
            'views_count' => 100,
            'likes_count' => 20,
            'shares_count' => 5,
        ]);

        // Submit batch of 4 events
        $res = $this->postJson('/api/v1/videos/events/batch', [
            'events' => [
                ['video_id' => $video->id, 'event_type' => 'play', 'duration_watched' => 60, 'session_id' => 's1'],
                ['video_id' => $video->id, 'event_type' => 'play', 'duration_watched' => 120, 'session_id' => 's2'],
                ['video_id' => $video->id, 'event_type' => 'like', 'session_id' => 's1'],
                ['video_id' => $video->id, 'event_type' => 'share', 'session_id' => 's3'],
            ],
        ]);

        $res->assertStatus(202)
            ->assertJsonPath('data.processed', 4);

        $video->refresh();
        $this->assertEquals(102, $video->views_count);
        $this->assertEquals(21, $video->likes_count);
        $this->assertEquals(6, $video->shares_count);

        $this->assertDatabaseCount('video_events', 4);
    }
}
