<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

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
}
