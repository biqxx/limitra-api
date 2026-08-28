<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Payment\SavedCard;
use App\Models\User;
use App\Models\User\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerAccountCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_profile_update_requires_verification_before_changing_email(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->actingAs($user, 'api')->patchJson('/api/v1/profile', [
            'name' => 'Lucy Limitra',
            'username' => 'lucy-limitra',
            'email' => 'new-lucy@example.com',
            'phone' => '+2348000000000',
            'date_of_birth' => '1995-05-10',
            'gender' => 'female',
        ])->assertOk()
            ->assertJsonPath('data.email', 'lucy@example.com')
            ->assertJsonPath('data.name', 'Lucy Limitra');

        $user->refresh();
        $this->assertSame('new-lucy@example.com', $user->pending_email);
        $this->assertSame('lucy@example.com', $user->email);

        $user->update(['email_change_otp' => Hash::make('123456')]);
        $this->actingAs($user, 'api')->postJson('/api/v1/profile/email/verify', ['otp' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new-lucy@example.com');
    }

    public function test_avatar_upload_replaces_and_deletes_public_file(): void
    {
        Storage::fake('public');
        $user = $this->user();

        $response = $this->actingAs($user, 'api')->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.webp', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $user->profile->fresh()->avatar;
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.avatar_url'));

        $this->actingAs($user, 'api')->deleteJson('/api/v1/profile/avatar')->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_addresses_use_frontend_shape_and_transactional_defaults(): void
    {
        $user = $this->user();
        $payload = [
            'type' => 'delivery',
            'label' => 'Home',
            'recipient_name' => 'Lucy Limitra',
            'phone' => '+2348000000000',
            'line1' => '14 Admiralty Way',
            'city' => 'Lekki',
            'state' => 'Lagos',
            'country' => 'NG',
            'is_default' => true,
        ];

        $first = $this->actingAs($user, 'api')->postJson('/api/v1/addresses', $payload)
            ->assertCreated()->assertJsonPath('data.line1', '14 Admiralty Way');
        $second = $this->actingAs($user, 'api')->postJson('/api/v1/addresses', array_merge($payload, ['line1' => '22 Marina Road']))
            ->assertCreated();

        $this->assertDatabaseHas('addresses', ['id' => $first->json('data.id'), 'is_default' => false]);
        $this->assertDatabaseHas('addresses', ['id' => $second->json('data.id'), 'is_default' => true]);
    }

    public function test_saved_card_metadata_comes_only_from_verified_paystack_transaction(): void
    {
        $user = $this->user();
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'customer' => ['email' => $user->email, 'customer_code' => 'CUS_123'],
                'authorization' => [
                    'authorization_code' => 'AUTH_secret',
                    'signature' => 'SIG_unique',
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => '12',
                    'exp_year' => '2029',
                    'account_name' => 'Lucy Limitra',
                    'reusable' => true,
                ],
            ],
        ])]);

        $this->actingAs($user, 'api')->postJson('/api/v1/saved-cards', [
            'provider' => 'paystack',
            'reference' => 'PAY_reference',
            'make_default' => true,
        ])->assertCreated()
            ->assertJsonPath('data.brand', 'visa')
            ->assertJsonPath('data.last4', '4242')
            ->assertJsonMissingPath('data.authorization_code');

        $stored = SavedCard::firstOrFail();
        $this->assertSame('AUTH_secret', $stored->authorization_code);
        $this->assertStringNotContainsString('AUTH_secret', (string) \DB::table('saved_cards')->value('authorization_code'));
    }

    public function test_login_sessions_can_be_listed_and_revoked(): void
    {
        $user = $this->user();
        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Chrome on Windows',
        ])->assertOk();
        $token = $login->json('data.access_token');

        $sessions = $this->withToken($token)->getJson('/api/v1/account/sessions')
            ->assertOk()->assertJsonPath('data.0.device_name', 'Chrome on Windows');
        $sessionId = $sessions->json('data.0.id');

        $this->withToken($token)->deleteJson("/api/v1/account/sessions/{$sessionId}", [
            'current_password' => 'password',
        ])->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function user(): User
    {
        $user = User::create([
            'username' => 'lucy',
            'email' => 'lucy@example.com',
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
        Profile::create(['user_id' => $user->id, 'first_name' => 'Lucy']);

        return $user->load('profile');
    }
}
