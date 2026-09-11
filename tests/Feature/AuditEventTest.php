<?php

namespace Tests\Feature;

use App\Models\Admin\AuditEvent;
use App\Models\User;
use App\Services\Admin\AuditEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use LogicException;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_records_actor_subject_context_and_redacts_sensitive_values(): void
    {
        $actor = $this->user('admin');
        $subject = $this->user('user');
        $request = Request::create(
            '/api/v1/admin/users/'.$subject->id,
            'PATCH',
            server: [
                'HTTP_X_CORRELATION_ID' => 'corr-audit-123',
                'HTTP_USER_AGENT' => str_repeat('a', 600),
                'REMOTE_ADDR' => '203.0.113.25',
            ],
        );
        $request->setUserResolver(fn (?string $guard = null): User => $actor);

        $event = app(AuditEventService::class)->record(
            action: 'user.suspended',
            subject: $subject,
            reason: 'Repeated payment abuse.',
            before: ['status' => 'active', 'password' => 'must-not-persist'],
            after: ['status' => 'suspended'],
            metadata: [
                'source' => 'admin',
                'credentials' => ['access_token' => 'must-not-persist'],
            ],
            request: $request,
        );

        $this->assertTrue($event->actor->is($actor));
        $this->assertTrue($event->subject->is($subject));
        $this->assertSame('corr-audit-123', $event->request_id);
        $this->assertSame('203.0.113.25', $event->ip_address);
        $this->assertSame(512, strlen($event->user_agent));
        $this->assertSame('[REDACTED]', $event->before_values['password']);
        $this->assertSame('[REDACTED]', $event->metadata['credentials']['access_token']);
        $this->assertSame('active', $event->before_values['status']);
        $this->assertSame('suspended', $event->after_values['status']);
    }

    public function test_service_can_record_a_system_event_without_an_actor_or_subject(): void
    {
        $event = app(AuditEventService::class)->record(
            action: 'inventory.reconciled',
            metadata: ['adjustments' => 3],
        );

        $this->assertNull($event->actor_id);
        $this->assertNull($event->subject_type);
        $this->assertNull($event->subject_id);
        $this->assertSame(['adjustments' => 3], $event->metadata);
    }

    public function test_audit_events_cannot_be_updated_or_deleted_through_eloquent(): void
    {
        $event = AuditEvent::factory()->create(['action' => 'user.created']);

        try {
            $event->update(['action' => 'user.deleted']);
            $this->fail('Updating an audit event should throw an exception.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit events are immutable.', $exception->getMessage());
        }

        $this->assertSame('user.created', $event->refresh()->action);

        try {
            $event->delete();
            $this->fail('Deleting an audit event should throw an exception.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit events cannot be deleted.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_events', ['id' => $event->id]);
    }

    public function test_deleting_an_actor_does_not_delete_or_rewrite_the_audit_event(): void
    {
        $actor = $this->user('admin');
        $event = app(AuditEventService::class)->record(
            action: 'user.created',
            actor: $actor,
        );
        $actorId = $actor->id;

        $actor->delete();

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'actor_id' => $actorId,
        ]);
        $this->assertNull($event->fresh()->actor);
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
