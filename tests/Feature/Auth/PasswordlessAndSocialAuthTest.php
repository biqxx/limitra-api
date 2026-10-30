<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordlessAndSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_passwordless_otp_request_and_verify_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'otpuser@example.com',
            'role' => 'user',
        ]);

        $reqRes = $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'otpuser@example.com',
        ]);

        $reqRes->assertOk()
            ->assertJsonPath('success', true);

        // Code was cached
        $cacheKey = 'otp_login_otpuser@example.com';
        $this->assertTrue(Cache::has($cacheKey));

        // Inject known hash to test verification
        Cache::put($cacheKey, [
            'hash' => Hash::make('654321'),
            'attempts' => 0,
        ], now()->addMinutes(10));

        // Verify with invalid code
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'otpuser@example.com',
            'code' => '000000',
        ])->assertStatus(422);

        // Verify with valid code
        $verRes = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'otpuser@example.com',
            'code' => '654321',
            'device_name' => 'Chrome on Windows',
        ]);

        $verRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'otpuser@example.com')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_in', 'user']]);
    }

    public function test_passwordless_otp_creates_new_user_if_not_found(): void
    {
        $email = 'brandnew@example.com';
        $cacheKey = "otp_login_{$email}";

        Cache::put($cacheKey, [
            'hash' => Hash::make('123456'),
            'attempts' => 0,
        ], now()->addMinutes(10));

        $verRes = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => $email,
            'code' => '123456',
        ]);

        $verRes->assertOk()
            ->assertJsonPath('data.user.email', $email);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role' => 'user',
        ]);
    }

    public function test_social_auth_redirect_and_callback(): void
    {
        // Redirect
        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $redRes->assertOk()
            ->assertJsonPath('data.provider', 'google')
            ->assertJsonStructure(['data' => ['authorization_url', 'state']]);

        $state = $redRes->json('data.state');

        // Callback
        $cbRes = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'auth_code_from_google',
            'provider_user_id' => 'goog_123456789',
            'email' => 'googleuser@gmail.com',
            'name' => 'Google User',
        ]);

        $cbRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'googleuser@gmail.com')
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('social_accounts', [
            'platform' => 'google',
            'platform_sender_id' => 'goog_123456789',
        ]);
    }
}
