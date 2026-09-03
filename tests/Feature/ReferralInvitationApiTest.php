<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\RecordReferralShareEvent;
use App\Jobs\SendReferralInvitation;
use App\Models\Referral\CustomerReferralInvitation;
use App\Models\User;
use App\Notifications\ReferralInvitationNotification;
use App\Services\Referral\CustomerReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReferralInvitationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'app.frontend_url' => 'https://shop.example.test',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
        Cache::flush();
        Queue::fake();
    }

    public function test_email_invitation_is_private_queued_and_owner_scoped(): void
    {
        $user = $this->user('inviter@example.test');
        $other = $this->user('other@example.test');

        $response = $this->actingAs($user, 'api')->postJson('/api/v1/referrals/invitations', [
            'email' => 'Friend@Example.test',
            'message' => 'You may like this store.',
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.target', 'f***@example.test')
            ->assertJsonPath('data.status', 'queued');

        $invitation = CustomerReferralInvitation::query()->firstOrFail();
        $this->assertSame('friend@example.test', $invitation->target_ciphertext);
        $storedTarget = DB::table('customer_referral_invitations')->value('target_ciphertext');
        $this->assertNotSame('friend@example.test', $storedTarget);
        $this->assertStringNotContainsString('friend@example.test', serialize(new SendReferralInvitation($invitation->id)));
        Queue::assertPushed(SendReferralInvitation::class, fn (SendReferralInvitation $job): bool => $job->invitationId === $invitation->id
            && $job->queue === 'notifications');

        $this->actingAs($user, 'api')->getJson('/api/v1/referrals/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $response->json('data.id'))
            ->assertJsonMissing(['target_hash' => $invitation->target_hash])
            ->assertJsonMissing(['target_ciphertext' => 'friend@example.test']);
        $this->actingAs($other, 'api')->getJson('/api/v1/referrals/invitations')
            ->assertOk()->assertJsonCount(0, 'data.items');

        Notification::fake();
        (new SendReferralInvitation($invitation->id))->handle();
        Notification::assertSentOnDemand(ReferralInvitationNotification::class);
        $this->assertSame('sent', $invitation->fresh()->status);
        $this->assertNotNull($invitation->fresh()->sent_at);
    }

    public function test_duplicate_and_daily_limited_invitations_are_enforced_from_database_settings(): void
    {
        $user = $this->user('limited@example.test');
        DB::table('business_settings')->where('key', 'referrals.invitation_daily_limit')
            ->update(['value' => json_encode(1, JSON_THROW_ON_ERROR)]);
        Cache::forget('business_settings.values.v1');
        $payload = ['email' => 'same@example.test'];

        $first = $this->actingAs($user, 'api')->postJson('/api/v1/referrals/invitations', $payload)->assertCreated();
        $second = $this->actingAs($user, 'api')->postJson('/api/v1/referrals/invitations', $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('customer_referral_invitations', 1);
        Queue::assertPushed(SendReferralInvitation::class, 1);

        $this->actingAs($user, 'api')->postJson('/api/v1/referrals/invitations', [
            'email' => 'different@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('invitation');
    }

    public function test_phone_invitation_is_recorded_without_claiming_external_delivery(): void
    {
        $user = $this->user('phone@example.test');

        $this->actingAs($user, 'api')->postJson('/api/v1/referrals/invitations', [
            'phone' => '+2348012345678',
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'phone')
            ->assertJsonPath('data.target', '**********5678')
            ->assertJsonPath('data.status', 'pending_provider');

        Queue::assertNotPushed(SendReferralInvitation::class);
        $this->actingAs($user, 'api')->postJson('/api/v1/referrals/invitations', [
            'email' => 'both@example.test',
            'phone' => '+2348012345678',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_share_event_is_owner_validated_and_queued_with_hashed_context(): void
    {
        $user = $this->user('share@example.test');
        $code = app(CustomerReferralService::class)->codeFor($user);
        $url = "https://shop.example.test/ref/{$code->code}?utm_source=whatsapp";

        $response = $this->actingAs($user, 'api')->postJson('/api/v1/referrals/share-events', [
            'code' => $code->code,
            'channel' => 'whatsapp',
            'url' => $url,
        ])->assertStatus(202)
            ->assertJsonPath('message', 'Referral share event accepted.');

        Queue::assertPushed(RecordReferralShareEvent::class, function (RecordReferralShareEvent $job) use ($response, $url): bool {
            $this->assertSame($response->json('data.event_id'), $job->eventId);
            $this->assertNotSame($url, $job->sharedUrlHash);
            $this->assertSame('analytics', $job->queue);
            $job->handle();

            return true;
        });
        $this->assertDatabaseHas('customer_referral_share_events', [
            'event_id' => $response->json('data.event_id'),
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'shared_path' => '/ref/'.$code->code,
        ]);

        $this->actingAs($user, 'api')->postJson('/api/v1/referrals/share-events', [
            'code' => $code->code,
            'channel' => 'copy',
            'url' => 'https://evil.example/ref/'.$code->code,
        ])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'username' => (string) str($email)->before('@'),
            'email' => $email,
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }
}
