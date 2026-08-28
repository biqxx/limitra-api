<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
    }

    public function test_signup_accepts_frontend_fields_and_sends_verification_otp(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/signup', [
            'name' => 'Lucy Limitra',
            'email' => 'lucy@example.com',
            'phone' => '+2348000000000',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $user = User::where('email', 'lucy@example.com')->firstOrFail();

        $this->assertSame('lucy_limitra', $user->username);
        $this->assertNotNull($user->email_verification_otp);
        $this->assertTrue($user->email_verification_expires_at->isFuture());
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'first_name' => 'Lucy',
            'last_name' => 'Limitra',
            'phone' => '+2348000000000',
        ]);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_valid_otp_verifies_email_and_returns_jwt(): void
    {
        $user = $this->unverifiedUser('123456');

        $this->postJson('/api/v1/verify-email', [
            'email' => $user->email,
            'otp' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'bearer')
            ->assertJsonPath('data.user.email', $user->email);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_otp);
    }

    public function test_expired_otp_is_rejected_and_cleared(): void
    {
        $user = $this->unverifiedUser('123456', now()->subMinute());

        $this->postJson('/api/v1/verify-email', [
            'email' => $user->email,
            'otp' => '123456',
        ])->assertUnprocessable();

        $this->assertNull($user->fresh()->email_verification_otp);
    }

    public function test_resend_does_not_reveal_account_existence(): void
    {
        Notification::fake();
        $user = $this->unverifiedUser('123456');

        $known = $this->postJson('/api/v1/resend-verification', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/resend-verification', ['email' => 'unknown@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame(0, $user->fresh()->email_verification_attempts);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    private function unverifiedUser(string $otp, mixed $expiresAt = null): User
    {
        return User::create([
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'user',
            'email_verification_otp' => Hash::make($otp),
            'email_verification_expires_at' => $expiresAt ?? now()->addMinutes(10),
            'email_verification_sent_at' => now(),
        ]);
    }
}
