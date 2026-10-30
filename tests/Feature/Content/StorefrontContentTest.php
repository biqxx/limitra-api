<?php

namespace Tests\Feature\Content;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Content\BlogPost;
use App\Models\Content\CareerJob;
use App\Models\Content\ContentEntry;
use App\Models\Content\Video;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_public_content_page_and_home_payload(): void
    {
        ContentEntry::create([
            'slug' => 'about-us',
            'title' => 'About Limitra',
            'body' => '<p>Limitra is the leading African e-commerce ecosystem.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/content/about-us')
            ->assertOk()
            ->assertJsonPath('data.title', 'About Limitra');

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['categories', 'featured_products', 'promotions', 'featured_videos']]);
    }

    public function test_admin_content_page_lifecycle_and_publish(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Create draft
        $createRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/content', [
                'title' => 'Terms of Service',
                'slug' => 'terms',
                'body' => 'Original draft terms',
                'status' => 'draft',
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $entryId = $createRes->json('data.id');

        // Cannot view draft publicly
        $this->getJson('/api/v1/content/terms')
            ->assertNotFound();

        // Publish
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/content/{$entryId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // Now accessible publicly
        $this->getJson('/api/v1/content/terms')
            ->assertOk()
            ->assertJsonPath('data.title', 'Terms of Service');
    }

    public function test_blog_post_lifecycle_and_public_filtering(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $post = BlogPost::create([
            'title' => 'E-Commerce Trends 2026',
            'slug' => 'ecommerce-trends-2026',
            'excerpt' => 'Key trends in global trade',
            'content' => 'Full article contents on logistics and payments...',
            'category' => 'Technology',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/blog/posts?category=Technology')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.slug', 'ecommerce-trends-2026');

        $this->getJson('/api/v1/blog/posts/ecommerce-trends-2026')
            ->assertOk()
            ->assertJsonPath('data.title', 'E-Commerce Trends 2026');

        // Admin can update
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/blog/posts/{$post->id}", [
                'title' => 'E-Commerce Trends 2026 (Updated)',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'E-Commerce Trends 2026 (Updated)');
    }

    public function test_video_events_and_counters(): void
    {
        $video = Video::create([
            'title' => 'Unboxing Limitra Phone',
            'slug' => 'unboxing-limitra-phone',
            'url' => 'https://youtube.com/watch?v=sample123',
            'status' => 'published',
            'views_count' => 10,
            'likes_count' => 5,
        ]);

        $this->postJson("/api/v1/videos/{$video->id}/events", [
            'event_type' => 'play',
        ])->assertOk();

        $this->postJson("/api/v1/videos/{$video->id}/events", [
            'event_type' => 'like',
        ])->assertOk();

        $this->assertEquals(11, $video->fresh()->views_count);
        $this->assertEquals(6, $video->fresh()->likes_count);
    }

    public function test_newsletter_subscribe_and_unsubscribe(): void
    {
        $res = $this->postJson('/api/v1/newsletter/subscriptions', [
            'email' => 'Subscriber@Example.com',
            'source' => 'popup',
        ]);

        $res->assertStatus(202)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('newsletter_subscriptions', [
            'email' => 'subscriber@example.com',
            'status' => 'subscribed',
        ]);

        // Unsubscribe
        $this->postJson('/api/v1/newsletter/unsubscribe', [
            'email' => 'subscriber@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('newsletter_subscriptions', [
            'email' => 'subscriber@example.com',
            'status' => 'unsubscribed',
        ]);
    }

    public function test_career_job_listing_and_application_submission(): void
    {
        $job = CareerJob::create([
            'title' => 'Senior Backend Engineer',
            'slug' => 'senior-backend-engineer',
            'department' => 'Engineering',
            'location' => 'Lagos / Remote',
            'description' => 'Build high-scale fintech systems',
            'status' => 'open',
        ]);

        $this->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson('/api/v1/jobs/senior-backend-engineer')
            ->assertOk()
            ->assertJsonPath('data.title', 'Senior Backend Engineer');

        // Apply to job
        $this->postJson("/api/v1/jobs/{$job->id}/applications", [
            'name' => 'Jane Dev',
            'email' => 'jane@example.com',
            'cv_url' => 'https://storage.limitra.test/cv/jane.pdf',
            'cover_letter' => 'Excited to apply!',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('career_applications', [
            'career_job_id' => $job->id,
            'email' => 'jane@example.com',
        ]);
    }
}
