<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Social\SocialAccount;
use App\Models\User;
use App\Notifications\LoginOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordlessAndSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect' => 'https://limitra.test/api/v1/auth/social/google/callback',
            'services.facebook.client_id' => 'test-fb-client-id',
            'services.facebook.client_secret' => 'test-fb-client-secret',
            'services.facebook.redirect' => 'https://limitra.test/api/v1/auth/social/facebook/callback',
            'services.apple.client_id' => 'test-apple-client-id',
            'services.apple.client_secret' => 'test-apple-client-secret',
            'services.apple.redirect' => 'https://limitra.test/api/v1/auth/social/apple/callback',
            'auth.passwordless.allow_signup' => false,
            'auth.passwordless.ttl_minutes' => 10,
            'auth.passwordless.max_attempts' => 5,
            'auth.passwordless.cooldown_seconds' => 60,
        ]);

        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_passwordless_otp_request_sends_queued_notification_and_generic_response(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'otpuser@example.com',
            'role' => 'user',
        ]);

        $reqRes = $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'OTPUSER@example.com',
        ]);

        $reqRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery', 'email')
            ->assertJsonPath('data.expires_in_minutes', 10);

        Notification::assertSentTo($user, LoginOtpNotification::class, function ($notification) {
            return $notification->via(new \stdClass) === ['mail'];
        });

        // Code was cached with attempts and fixed expires_at
        $cacheKey = 'otp_login_otpuser@example.com';
        $this->assertTrue(Cache::has($cacheKey));
        $cached = Cache::get($cacheKey);
        $this->assertSame(0, $cached['attempts']);
        $this->assertArrayHasKey('expires_at', $cached);
    }

    public function test_passwordless_otp_request_prevents_account_enumeration_for_nonexistent_email(): void
    {
        Notification::fake();

        $reqRes = $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'unknown@example.com',
        ]);

        $reqRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery', 'email')
            ->assertJsonPath('data.expires_in_minutes', 10);

        Notification::assertNothingSent();
    }

    public function test_passwordless_otp_request_enforces_resend_cooldown(): void
    {
        $user = User::factory()->create([
            'email' => 'cooldown@example.com',
        ]);

        $first = $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'cooldown@example.com',
        ]);
        $first->assertOk();

        $second = $this->postJson('/api/v1/auth/otp/request', [
            'email' => 'cooldown@example.com',
        ]);
        $second->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    public function test_passwordless_otp_verify_rejects_invalid_code_and_does_not_extend_expiration(): void
    {
        $user = User::factory()->create([
            'email' => 'verifyuser@example.com',
        ]);

        $expiresAt = now()->addMinutes(10)->timestamp;
        $cacheKey = 'otp_login_verifyuser@example.com';
        Cache::put($cacheKey, [
            'hash' => Hash::make('654321'),
            'attempts' => 0,
            'expires_at' => $expiresAt,
        ], 600);

        // Invalid code
        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'verifyuser@example.com',
            'code' => '000000',
        ]);
        $res->assertStatus(422)
            ->assertJsonPath('success', false);

        $cached = Cache::get($cacheKey);
        $this->assertSame(1, $cached['attempts']);
        // Crucial test: expiration timestamp was fixed, not extended
        $this->assertSame($expiresAt, $cached['expires_at']);

        // Valid code verification
        $validRes = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'verifyuser@example.com',
            'code' => '654321',
        ]);
        $validRes->assertOk()
            ->assertJsonPath('data.user.email', 'verifyuser@example.com')
            ->assertJsonStructure(['data' => ['token', 'user']]);

        // Code cleared after success
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_passwordless_otp_verify_enforces_maximum_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'maxattempts@example.com',
        ]);

        $cacheKey = 'otp_login_maxattempts@example.com';
        Cache::put($cacheKey, [
            'hash' => Hash::make('111222'),
            'attempts' => 5, // already at max
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], 600);

        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'maxattempts@example.com',
            'code' => '000000',
        ]);

        $res->assertStatus(429);
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_passwordless_otp_verify_policy_blocks_creation_when_disabled(): void
    {
        config(['auth.passwordless.allow_signup' => false]);

        $email = 'notexists@example.com';
        Cache::put("otp_login_{$email}", [
            'hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], 600);

        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => $email,
            'code' => '123456',
        ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_passwordless_otp_verify_policy_allows_creation_when_enabled(): void
    {
        config(['auth.passwordless.allow_signup' => true]);

        $email = 'brandnew@example.com';
        Cache::put("otp_login_{$email}", [
            'hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], 600);

        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => $email,
            'code' => '123456',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.user.email', $email);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'role' => 'user',
        ]);
    }

    public function test_passwordless_otp_blocks_suspended_user(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'status' => 'suspended',
        ]);

        Cache::put('otp_login_suspended@example.com', [
            'hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], 600);

        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'suspended@example.com',
            'code' => '123456',
        ]);

        $res->assertStatus(403);
    }

    public function test_social_auth_redirect_and_single_use_state_verification(): void
    {
        // 1. Redirect
        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $redRes->assertOk()
            ->assertJsonPath('data.provider', 'google')
            ->assertJsonStructure(['data' => ['authorization_url', 'state']]);

        $state = $redRes->json('data.state');
        $this->assertTrue(Cache::has("social_state_{$state}"));

        // Build valid Google id_token JWT
        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'google-user-12345',
            'email' => 'verified@gmail.com',
            'email_verified' => true,
            'name' => 'Verified Google User',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'id_token' => $idToken,
                'token_type' => 'Bearer',
            ]),
        ]);

        // 2. Successful callback
        $cbRes = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'valid-auth-code',
        ]);

        $cbRes->assertOk()
            ->assertJsonPath('data.user.email', 'verified@gmail.com');

        $this->assertDatabaseHas('social_accounts', [
            'platform' => 'google',
            'platform_sender_id' => 'google-user-12345',
        ]);

        // 3. Replay attack: reusing the same state must fail with 422
        $replayRes = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'valid-auth-code',
        ]);

        $replayRes->assertStatus(422);
    }

    public function test_social_auth_callback_ignores_forged_frontend_identity_claims(): void
    {
        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'real-google-id-888',
            'email' => 'real@gmail.com',
            'email_verified' => true,
            'name' => 'Real Name',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'id_token' => $idToken,
            ]),
        ]);

        // Client attempts to impersonate victim@example.com
        $res = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'valid-code',
            'email' => 'victim@example.com',
            'provider_user_id' => 'victim-999',
            'name' => 'Fake Victim',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.user.email', 'real@gmail.com');

        $this->assertDatabaseMissing('users', ['email' => 'victim@example.com']);
        $this->assertDatabaseHas('social_accounts', [
            'platform' => 'google',
            'platform_sender_id' => 'real-google-id-888',
        ]);
    }

    public function test_social_auth_callback_rejects_unverified_provider_email(): void
    {
        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'unverified-sub',
            'email' => 'unverified@gmail.com',
            'email_verified' => false, // Provider explicitly flags unverified
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-access-token',
                'id_token' => $idToken,
            ]),
        ]);

        $res = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'code-123',
        ]);

        $res->assertStatus(422);
    }

    public function test_social_auth_prevents_account_takeover_when_email_exists_without_linking(): void
    {
        // An existing user registered with password
        $existing = User::factory()->create([
            'email' => 'victim@limitra.com',
        ]);

        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'attacker-or-unlinked-sub',
            'email' => 'victim@limitra.com',
            'email_verified' => true,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'id_token' => $idToken,
            ]),
        ]);

        // Attempting to log in as victim without explicit authenticated linking must fail with 409 Conflict
        $res = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'code-123',
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_social_auth_allows_explicit_authenticated_linking(): void
    {
        $user = User::factory()->create([
            'email' => 'user@limitra.com',
        ]);

        // User is logged in when generating redirect or has token
        $redRes = $this->actingAs($user, 'api')
            ->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'google-user-link-777',
            'email' => 'another-email@gmail.com',
            'email_verified' => true,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'id_token' => $idToken,
            ]),
        ]);

        $res = $this->actingAs($user, 'api')
            ->postJson('/api/v1/auth/social/google/callback', [
                'state' => $state,
                'code' => 'code-123',
            ]);

        $res->assertOk();

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'platform' => 'google',
            'platform_sender_id' => 'google-user-link-777',
        ]);
    }

    public function test_social_auth_rejects_linking_conflict_when_claimed_by_another_user(): void
    {
        $bob = User::factory()->create(['email' => 'bob@limitra.com']);
        SocialAccount::create([
            'user_id' => $bob->id,
            'platform' => 'google',
            'platform_sender_id' => 'shared-google-sub',
        ]);

        $alice = User::factory()->create(['email' => 'alice@limitra.com']);

        $redRes = $this->actingAs($alice, 'api')
            ->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'shared-google-sub',
            'email' => 'bob-google@gmail.com',
            'email_verified' => true,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'id_token' => $idToken,
            ]),
        ]);

        $res = $this->actingAs($alice, 'api')
            ->postJson('/api/v1/auth/social/google/callback', [
                'state' => $state,
                'code' => 'code-123',
            ]);

        $res->assertStatus(409);
    }

    public function test_social_auth_blocks_suspended_user(): void
    {
        $user = User::factory()->create([
            'email' => 'banned@gmail.com',
            'status' => 'suspended',
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'google',
            'platform_sender_id' => 'banned-sub-99',
        ]);

        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'banned-sub-99',
            'email' => 'banned@gmail.com',
            'email_verified' => true,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'id_token' => $idToken,
            ]),
        ]);

        $res = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'code-123',
        ]);

        $res->assertStatus(403);
    }

    public function test_social_auth_callback_rejects_mismatched_provider(): void
    {
        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        // Post state generated for Google to Facebook callback
        $res = $this->postJson('/api/v1/auth/social/facebook/callback', [
            'state' => $state,
            'code' => 'code-123',
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_social_auth_callback_rejects_invalid_or_failed_code_exchange(): void
    {
        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Code has expired',
            ], 400),
        ]);

        $res = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'expired-code',
        ]);

        $res->assertStatus(422);
    }

    public function test_social_auth_callback_rejects_mismatched_nonce(): void
    {
        $redRes = $this->getJson('/api/v1/auth/social/google/redirect');
        $state = $redRes->json('data.state');

        $idToken = $this->makeJwt([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'sub' => 'sub-123',
            'email' => 'user@gmail.com',
            'email_verified' => true,
            'nonce' => 'forged-or-mismatched-nonce',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'mock-token',
                'id_token' => $idToken,
            ]),
        ]);

        $res = $this->postJson('/api/v1/auth/social/google/callback', [
            'state' => $state,
            'code' => 'code-123',
        ]);

        $res->assertStatus(422);
    }

    /**
     * Helper to forge a test JWT for mocking Google/Apple id_token responses.
     */
    private function makeJwt(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'RS256'];
        $base64Header = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $base64Payload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake-signature-bytes'), '+/', '-_'), '=');

        return "{$base64Header}.{$base64Payload}.{$signature}";
    }
}
