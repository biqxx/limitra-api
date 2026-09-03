<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCustomerNotification;
use App\Notifications\SupportTicketStaffNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_attachments_are_private_and_downloadable_only_by_owner_or_staff(): void
    {
        Storage::fake('local');
        Notification::fake();
        $owner = $this->user('attachment-owner@example.com');
        $other = $this->user('attachment-other@example.com');
        $staff = $this->user('attachment-staff@example.com', 'staff');

        $response = $this->actingAs($owner, 'api')->post('/api/v1/support/tickets', [
            'category' => 'technical',
            'subject' => 'Screenshot of the issue',
            'message' => 'This screenshot shows the technical issue I encountered.',
            'attachments' => [UploadedFile::fake()->image('issue.png')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonCount(1, 'data.messages.0.attachments');
        $ticket = SupportTicket::query()->firstOrFail();
        $attachment = $ticket->messages()->firstOrFail()->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($other, 'api')->get("/api/v1/support/attachments/{$attachment->id}")
            ->assertForbidden();
        $this->actingAs($owner, 'api')->get("/api/v1/support/attachments/{$attachment->id}")
            ->assertOk()->assertDownload('issue.png');
        $this->actingAs($staff, 'api')->get("/api/v1/support/attachments/{$attachment->id}")
            ->assertOk()->assertDownload('issue.png');
    }

    public function test_staff_and_customer_replies_update_workflow_and_send_notifications(): void
    {
        Notification::fake();
        $owner = $this->user('reply-owner@example.com');
        $staff = $this->user('reply-staff@example.com', 'staff');
        $ticket = $this->ticket($owner, 'Reply workflow');

        $this->actingAs($staff, 'api')->postJson("/api/v1/support/tickets/{$ticket->id}/messages", [
            'message' => 'We are investigating the problem and need one more detail.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'waiting_on_customer')
            ->assertJsonPath('data.messages.1.sender_type', 'staff');

        $this->assertNotNull($ticket->fresh()->first_responded_at);
        Notification::assertSentTo($owner, SupportTicketCustomerNotification::class);

        $this->actingAs($owner, 'api')->postJson("/api/v1/support/tickets/{$ticket->id}/messages", [
            'message' => 'The additional detail requested is included here.',
        ])->assertCreated()->assertJsonPath('data.status', 'open');
        Notification::assertSentTo($staff, SupportTicketStaffNotification::class);

        $this->actingAs($owner, 'api')->postJson("/api/v1/support/tickets/{$ticket->id}/close")
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseHas('support_ticket_events', [
            'support_ticket_id' => $ticket->id,
            'event' => 'closed',
        ]);
        $this->actingAs($owner, 'api')->postJson("/api/v1/support/tickets/{$ticket->id}/messages", [
            'message' => 'A closed ticket should reject this message.',
        ])->assertUnprocessable()->assertJsonValidationErrors('ticket');
    }

    public function test_admin_queue_filters_sla_and_records_assignment_changes(): void
    {
        Notification::fake();
        $owner = $this->user('queue-owner@example.com');
        $staff = $this->user('queue-staff@example.com', 'staff');
        $normalUser = $this->user('queue-normal@example.com');
        $ticket = $this->ticket($owner, 'Overdue delivery ticket', 'delivery');
        $ticket->forceFill([
            'first_response_due_at' => now()->subMinute(),
            'resolution_due_at' => now()->addDay(),
        ])->save();

        $this->actingAs($normalUser, 'api')->getJson('/api/v1/admin/support/tickets')->assertForbidden();
        $this->actingAs($staff, 'api')->getJson('/api/v1/admin/support/tickets?queue=delivery&sla=overdue&unassigned=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $ticket->id);

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/support/tickets/{$ticket->id}", [
            'status' => 'pending',
            'priority' => 'high',
            'assignee_id' => $staff->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.assignee.id', $staff->id)
            ->assertJsonPath('data.events.0.event', 'updated');

        $this->assertDatabaseHas('support_ticket_events', [
            'support_ticket_id' => $ticket->id,
            'actor_id' => $staff->id,
            'event' => 'updated',
        ]);
        Notification::assertSentTo($owner, SupportTicketCustomerNotification::class);
    }

    public function test_only_staff_can_be_assigned_and_status_transitions_are_enforced(): void
    {
        Notification::fake();
        $owner = $this->user('transition-owner@example.com');
        $staff = $this->user('transition-staff@example.com', 'staff');
        $ticket = $this->ticket($owner, 'Transition ticket');

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/support/tickets/{$ticket->id}", [
            'assignee_id' => $owner->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/support/tickets/{$ticket->id}", [
            'status' => 'resolved',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');

        $this->actingAs($staff, 'api')->patchJson("/api/v1/admin/support/tickets/{$ticket->id}", [
            'status' => 'pending',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    private function ticket(User $owner, string $subject, string $category = 'account'): SupportTicket
    {
        $this->actingAs($owner, 'api')->postJson('/api/v1/support/tickets', [
            'category' => $category,
            'subject' => $subject,
            'message' => 'This is the initial message for the support ticket.',
        ])->assertCreated();

        return SupportTicket::query()->latest('id')->firstOrFail();
    }

    private function user(string $email, string $role = 'user'): User
    {
        return User::query()->create([
            'username' => str($email)->before('@').'-'.fake()->unique()->numberBetween(100, 999),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
