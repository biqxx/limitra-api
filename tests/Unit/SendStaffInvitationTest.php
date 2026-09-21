<?php

namespace Tests\Unit;

use App\Enums\StaffInvitationStatus;
use App\Jobs\SendStaffInvitation;
use App\Models\User\StaffInvitation;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class SendStaffInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'app.frontend_url' => 'https://shop.example.test',
        ]);
    }

    public function test_job_sends_on_notifications_queue_without_serializing_secrets(): void
    {
        Notification::fake();
        $invitation = StaffInvitation::factory()->create([
            'email_ciphertext' => 'invitee@example.test',
            'token_ciphertext' => str_repeat('T', 64),
            'token_hash' => hash('sha256', str_repeat('T', 64)),
        ]);
        $job = new SendStaffInvitation($invitation->id, $invitation->delivery_version);

        $this->assertSame('notifications', $job->queue);
        $this->assertSame($invitation->id.':1', $job->uniqueId());
        $this->assertStringNotContainsString('invitee@example.test', serialize($job));
        $this->assertStringNotContainsString(str_repeat('T', 64), serialize($job));

        $job->handle();

        Notification::assertSentOnDemand(StaffInvitationNotification::class);
        $this->assertSame(StaffInvitationStatus::Sent, $invitation->fresh()->status);
        $this->assertNull($invitation->fresh()->token_ciphertext);
        $this->assertNotNull($invitation->fresh()->sent_at);
    }

    public function test_stale_delivery_version_is_ignored(): void
    {
        Notification::fake();
        $invitation = StaffInvitation::factory()->create(['delivery_version' => 2]);

        (new SendStaffInvitation($invitation->id, 1))->handle();

        Notification::assertNothingSent();
        $this->assertSame(StaffInvitationStatus::Queued, $invitation->fresh()->status);
    }

    public function test_mail_uses_a_fragment_token_in_the_frontend_acceptance_url(): void
    {
        $notification = new StaffInvitationNotification(
            'Ada',
            str_repeat('A', 64),
            'support_lead',
            now()->addDays(2),
        );
        $mail = $notification->toMail(new stdClass);

        $this->assertSame('https://shop.example.test/staff/invitation#token='.str_repeat('A', 64), $mail->actionUrl);
        $this->assertStringNotContainsString('?token=', (string) $mail->actionUrl);
    }

    public function test_failed_job_records_only_the_failure_class(): void
    {
        $invitation = StaffInvitation::factory()->create();
        $job = new SendStaffInvitation($invitation->id, $invitation->delivery_version);

        $job->failed(new RuntimeException('Secret provider response'));

        $invitation->refresh();
        $this->assertSame(StaffInvitationStatus::Failed, $invitation->status);
        $this->assertSame(RuntimeException::class, $invitation->failure_code);
        $this->assertStringNotContainsString('Secret provider response', (string) $invitation->failure_code);
    }
}
