<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Settings\BusinessSetting;
use App\Models\User;
use App\Notifications\Affiliate\AffiliateApplicationStatusNotification;
use App\Notifications\Affiliate\AffiliateApplicationSubmittedNotification;
use App\Notifications\Microstore\MicrostoreModeratedNotification;
use App\Notifications\Microstore\MicrostoreRegisteredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OnboardingNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_microstore_registration_and_admin_approval_send_notifications(): void
    {
        Notification::fake();

        BusinessSetting::updateOrCreate(
            ['key' => 'microstores.require_moderation'],
            ['value' => true, 'type' => 'boolean', 'group' => 'microstores']
        );

        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $createResponse = $this->actingAs($user, 'api')
            ->postJson('/api/v1/my-microstore', [
                'name' => 'Star Boutique',
            ])
            ->assertCreated();

        $microstoreId = $createResponse->json('data.id');

        Notification::assertSentTo($user, MicrostoreRegisteredNotification::class);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/microstores/{$microstoreId}/moderate", [
                'moderation_status' => 'approved',
                'moderation_notes' => 'Welcome aboard!',
            ])
            ->assertOk();

        Notification::assertSentTo($user, MicrostoreModeratedNotification::class);
    }

    public function test_affiliate_application_and_admin_approval_send_notifications(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $applyResponse = $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/applications', [
                'promotion_channels' => ['instagram', 'youtube'],
                'website_url' => 'https://example.com',
            ])
            ->assertCreated();

        $applicationId = $applyResponse->json('data.application.id');

        Notification::assertSentTo($user, AffiliateApplicationSubmittedNotification::class);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/affiliates/applications/{$applicationId}/approve", [
                'admin_notes' => 'Approved for affiliate program.',
            ])
            ->assertOk();

        Notification::assertSentTo($user, AffiliateApplicationStatusNotification::class);
    }
}

