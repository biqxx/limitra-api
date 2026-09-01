<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\ProcessAutomaticRefund;
use App\Jobs\ReconcileAutomaticRefund;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Models\User;
use App\Notifications\AutomaticRefundAttentionNotification;
use App\Notifications\AutomaticRefundProcessedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRefundOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_staff_can_filter_and_view_refunds_but_customers_cannot(): void
    {
        $attention = $this->refund(status: 'needs_attention');
        $attention->update(['reconciliation_attempts' => 3, 'last_reconciled_at' => now()]);
        $other = $this->refund(status: 'processed', source: 'return');
        $staff = $this->user('staff');
        $customer = $this->user('user');
        $attention->events()->create([
            'from_status' => 'pending',
            'to_status' => 'needs_attention',
            'action' => 'reconciliation_exhausted',
            'actor_id' => null,
            'note' => 'Provider status could not be confirmed.',
            'created_at' => now(),
        ]);

        $this->actingAs($staff, 'api')
            ->getJson('/api/v1/admin/refunds?attention_only=1&q='.$attention->order->number)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $attention->id)
            ->assertJsonPath('data.items.0.reconciliation.attempts', 3);

        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/admin/refunds/{$attention->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.action', 'reconciliation_exhausted')
            ->assertJsonPath('data.order.number', $attention->order->number);

        $this->actingAs($customer, 'api')->getJson('/api/v1/admin/refunds')->assertForbidden();
        $this->assertNotSame($attention->id, $other->id);
    }

    public function test_admin_can_idempotently_retry_a_definite_submission_failure(): void
    {
        Queue::fake();
        $refund = $this->refund(status: 'failed');
        $refund->update(['failure_message' => 'Paystack rejected the initial request.']);
        $staff = $this->user('staff');
        $admin = $this->user('admin');

        $this->actingAs($staff, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/retry", [], ['Idempotency-Key' => 'retry-refund-001'])
            ->assertForbidden();
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/retry", [], ['Idempotency-Key' => 'retry-refund-001'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'initiating');
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/retry", [], ['Idempotency-Key' => 'retry-refund-001'])
            ->assertOk()
            ->assertJsonPath('data.status', 'initiating');

        Queue::assertPushed(ProcessAutomaticRefund::class, 1);
        Queue::assertNotPushed(ReconcileAutomaticRefund::class);
        $this->assertDatabaseHas('refund_events', [
            'refund_id' => $refund->id,
            'from_status' => 'failed',
            'to_status' => 'initiating',
            'action' => 'submission_retry_requested',
            'actor_id' => $admin->id,
        ]);
        $this->assertDatabaseCount('refund_events', 1);
    }

    public function test_retry_rechecks_a_refund_that_may_already_exist_at_provider(): void
    {
        Queue::fake();
        $refund = $this->refund(status: 'needs_attention', providerRefundId: '900002');
        $refund->update([
            'failure_message' => 'Provider confirmation timed out.',
            'attention_notification_queued_at' => now(),
        ]);
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/retry", [], ['Idempotency-Key' => 'retry-refund-002'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $refund = $refund->fresh();
        $this->assertNull($refund->failure_message);
        $this->assertNull($refund->attention_notification_queued_at);
        $this->assertNotNull($refund->next_reconciliation_at);
        Queue::assertPushed(ReconcileAutomaticRefund::class, 1);
        Queue::assertNotPushed(ProcessAutomaticRefund::class);
        $this->assertDatabaseHas('refund_events', [
            'refund_id' => $refund->id,
            'action' => 'reconciliation_requested',
        ]);
    }

    public function test_non_automatic_and_completed_refunds_cannot_be_retried_or_resolved(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $returnRefund = $this->refund(status: 'failed', source: 'return');
        $processed = $this->refund(status: 'processed');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$returnRefund->id}/retry", [], ['Idempotency-Key' => 'retry-return-001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('refund');
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$processed->id}/resolve", [
                'status' => 'failed',
                'note' => 'Cannot resolve a completed refund.',
            ], ['Idempotency-Key' => 'resolve-processed-001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('refund');

        Queue::assertNothingPushed();
    }

    public function test_admin_can_idempotently_record_manual_provider_completion(): void
    {
        Notification::fake();
        Http::fake();
        $refund = $this->refund(status: 'needs_attention');
        $admin = $this->user('admin');
        $payload = [
            'status' => 'processed',
            'note' => 'Confirmed as completed in the Paystack dashboard.',
            'provider_reference' => 'RFD-MANUAL-001',
            'provider_refund_id' => '900003',
            'processed_at' => now()->subMinute()->toIso8601String(),
        ];

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/resolve", $payload, ['Idempotency-Key' => 'resolve-refund-001'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.provider_reference', 'RFD-MANUAL-001');
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/resolve", $payload, ['Idempotency-Key' => 'resolve-refund-001'])
            ->assertOk();

        $refund = $refund->fresh();
        $this->assertSame($admin->id, $refund->processed_by);
        $this->assertSame('refunded', $refund->order->payment_status);
        $this->assertDatabaseHas('refund_events', [
            'refund_id' => $refund->id,
            'from_status' => 'needs_attention',
            'to_status' => 'processed',
            'action' => 'manually_processed',
            'actor_id' => $admin->id,
        ]);
        $this->assertDatabaseCount('refund_events', 1);
        Notification::assertSentTo($refund->user, AutomaticRefundProcessedNotification::class, 1);
        Http::assertNothingSent();

        $changed = [...$payload, 'note' => 'A conflicting manual resolution note.'];
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/resolve", $changed, ['Idempotency-Key' => 'resolve-refund-001'])
            ->assertConflict();
    }

    public function test_admin_can_record_a_manual_failure_with_customer_attention(): void
    {
        Notification::fake();
        $refund = $this->refund(status: 'pending');
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/resolve", [
                'status' => 'failed',
                'note' => 'Provider confirmed that no refund was completed.',
            ], ['Idempotency-Key' => 'resolve-refund-002'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('refund_events', [
            'refund_id' => $refund->id,
            'action' => 'manually_failed',
            'actor_id' => $admin->id,
        ]);
        $this->assertNull($refund->fresh()->next_reconciliation_at);
        Notification::assertSentTo($refund->user, AutomaticRefundAttentionNotification::class, 1);
    }

    public function test_manual_completion_requires_provider_confirmation_reference(): void
    {
        $refund = $this->refund(status: 'needs_attention');
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/refunds/{$refund->id}/resolve", [
                'status' => 'processed',
                'note' => 'Confirmed manually.',
            ], ['Idempotency-Key' => 'resolve-refund-003'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_reference');
    }

    private function refund(
        string $status,
        string $source = 'late_payment',
        ?string $providerRefundId = null,
    ): Refund {
        $user = $this->user('user');
        $order = Order::query()->create([
            'user_id' => $user->id,
            'number' => 'LMT-OPS-'.Str::upper(Str::random(12)),
            'currency' => 'NGN',
            'subtotal' => 2000,
            'grand_total' => 2000,
            'total_amount' => 2000,
            'status' => 'cancelled',
            'payment_status' => $status === 'processed' ? 'refunded' : 'paid',
            'fulfilment_status' => 'cancelled',
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => [],
        ]);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'provider' => 'paystack',
            'method' => 'card',
            'reference' => 'LMT-PAY-'.Str::upper(Str::random(12)),
            'status' => 'succeeded',
            'currency' => 'NGN',
            'amount' => 2000,
            'amount_minor' => 200000,
            'customer_email' => $user->email,
        ]);

        return Refund::query()->create([
            'return_request_id' => null,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'user_id' => $user->id,
            'reference' => 'LMT-AUTO-REF-'.Str::upper(Str::random(12)),
            'provider' => 'paystack',
            'method' => 'original_payment',
            'source' => $source,
            'automation_key' => 'ops:'.Str::uuid(),
            'status' => $status,
            'currency' => 'NGN',
            'amount' => 2000,
            'amount_minor' => 200000,
            'provider_refund_id' => $providerRefundId,
            'reason' => 'Automatic late payment refund.',
            'processed_at' => $status === 'processed' ? now() : null,
        ])->load(['user', 'order', 'payment']);
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'username' => $role.'-'.Str::lower(Str::random(10)),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
