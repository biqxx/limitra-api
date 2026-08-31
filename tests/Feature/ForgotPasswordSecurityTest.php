<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Models\User\AuthSession;
use App\Notifications\PasswordResetOtpNotification;
use App\Services\Auth\AuthSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_known_and_unknown_emails_receive_the_same_response(): void
    {
        Notification::fake();

        $user = User::create([
            'username' => 'known-user',
            'email' => 'known@example.com',
            'password' => 'password',
            'role' => 'user',
        ]);

        $knownResponse = $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ]);

        $unknownResponse = $this->postJson('/api/v1/forgot-password', [
            'email' => 'unknown@example.com',
        ]);

        $knownResponse->assertOk();
        $unknownResponse->assertOk();
        $this->assertSame($knownResponse->json(), $unknownResponse->json());

        Notification::assertSentTo($user, PasswordResetOtpNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'unknown@example.com']);
    }

    public function test_password_reset_revokes_and_invalidates_existing_sessions(): void
    {
        $user = User::create([
            'username' => 'reset-user',
            'email' => 'reset@example.com',
            'password' => 'password',
            'role' => 'user',
        ]);
        $session = AuthSession::create([
            'user_id' => $user->id,
            'device_name' => 'Existing device',
            'last_used_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $manager = app(AuthSessionManager::class);
        $manager->remember($session);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/reset-password', [
            'email' => $user->email,
            'otp' => '123456',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertFalse($manager->isActive($session->id, $user->id));
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }
}
