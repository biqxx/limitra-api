<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/user-preferences')
            ->assertUnauthorized();

        $this->putJson('/api/v1/user-preferences', ['theme' => 'dark'])
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_retrieve_default_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/user-preferences')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.theme', 'light')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.notifications_enabled', true);
    }

    public function test_authenticated_user_can_update_preferences_via_put(): void
    {
        $user = User::factory()->create();

        $payload = [
            'theme' => 'dark',
            'locale' => 'fr',
            'currency' => 'EUR',
            'notifications_enabled' => false,
            'marketing_emails' => true,
        ];

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/user-preferences', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.notifications_enabled', false)
            ->assertJsonPath('data.marketing_emails', true);

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme' => 'dark',
            'locale' => 'fr',
            'currency' => 'EUR',
            'notifications_enabled' => false,
            'marketing_emails' => true,
        ]);
    }

    public function test_authenticated_user_can_update_preferences_via_patch(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->patchJson('/api/v1/user-preferences', ['theme' => 'system'])
            ->assertOk()
            ->assertJsonPath('data.theme', 'system');
    }

    public function test_preference_validation_enforces_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->putJson('/api/v1/user-preferences', [
                'theme' => 'invalid-theme',
                'currency' => 'INVALID_LENGTH',
                'notifications_enabled' => 'not-a-boolean',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['theme', 'currency', 'notifications_enabled']);
    }

    public function test_preferences_are_isolated_per_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA, 'api')
            ->putJson('/api/v1/user-preferences', ['theme' => 'dark'])
            ->assertOk();

        $this->actingAs($userB, 'api')
            ->getJson('/api/v1/user-preferences')
            ->assertOk()
            ->assertJsonPath('data.theme', 'light');
    }
}
