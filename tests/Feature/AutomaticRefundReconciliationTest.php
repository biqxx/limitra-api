<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutomaticRefund;
use App\Jobs\ReconcileAutomaticRefund;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Models\User;
use App\Notifications\AutomaticRefundAttentionNotification;
use App\Notifications\AutomaticRefundProcessedNotification;
use App\Notifications\AutomaticRefundStaffAlert;
use App\Services\Payment\RefundReconciliationService;
use App\Services\Payment\RefundSettlementService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticRefundReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.paystack.secret_key' => 'sk_test_reconciliation',
            'services.paystack.base_url' => 'https://api.paystack.co',
        ]);
    }

    public function test_dispatcher_only_claims_due_automatic_refunds(): void
    {
        Queue::fake();
        $due = $this->refund(nextReconciliationAt: now()->subMinute());
        $future = $this->refund(status: 'processing', nextReconciliationAt: now()->addMinute());
        $manual = $this->refund(source: 'return', nextReconciliationAt: now()->subMinute());

        $count = app(RefundReconciliationService::class)->dispatchDue();

        $this->assertSame(1, $count);
        Queue::assertPushed(
            ReconcileAutomaticRefund::class,
            fn (ReconcileAutomaticRefund $job): bool => $job->refundId === $due->id,
        );
        Queue::assertNotPushed(
            ReconcileAutomaticRefund::class,
            fn (ReconcileAutomaticRefund $job): bool => in_array($job->refundId, [$future->id, $manual->id], true),
        );
        $this->assertTrue($due->fresh()->next_reconciliation_at->isFuture());
    }

    public function test_known_provider_refund_is_fetched_and_settled(): void
    {
        Notification::fake();
        $refund = $this->refund(status: 'processing', providerRefundId: '900001');
        Http::fake([
            'https://api.paystack.co/refund/900001' => Http::response([
                'status' => true,
                'data' => $this->providerRefund($refund, status: 'processed'),
            ]),
        ]);

        app(RefundReconciliationService::class)->reconcile($refund->id);

        $refund = $refund->fresh();
        $this->assertSame('processed', $refund->status);
        $this->assertSame(1, $refund->reconciliation_attempts);
        $this->assertNotNull($refund->last_reconciled_at);
        $this->assertNull($refund->next_reconciliation_at);
        $this->assertSame('refunded', $refund->order->payment_status);
        Notification::assertSentTo($refund->user, AutomaticRefundProcessedNotification::class, 1);
    }

    public function test_unknown_submission_is_matched_without_resubmitting_the_refund(): void
    {
        Notification::fake();
        $refund = $this->refund();
        $providerRefund = $this->providerRefund($refund, status: 'processed');
        $providerRefund['merchant_note'] = 'Automatic refund for late payment on order '.$refund->order->number.'.';
        Http::fake([
            'https://api.paystack.co/refund*' => Http::response([
                'status' => true,
                'data' => [$providerRefund],
            ]),
        ]);

        app(RefundReconciliationService::class)->reconcile($refund->id);

        $this->assertSame('processed', $refund->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.paystack.co/refund?transaction='.
                rawurlencode($refund->payment->reference).'&perPage=50&page=1'
        );
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_unresolved_refund_is_rescheduled_using_admin_setting(): void
    {
        $admin = $this->user('admin');
        app(BusinessSettingsService::class)->update([
            ['key' => 'payments.refund_reconciliation_interval_minutes', 'value' => 30],
        ], $admin);
        $refund = $this->refund(createdAt: now()->subHour());
        Http::fake([
            'https://api.paystack.co/refund*' => Http::response(['status' => true, 'data' => []]),
        ]);

        app(RefundReconciliationService::class)->reconcile($refund->id);

        $refund = $refund->fresh();
        $this->assertSame('pending', $refund->status);
        $this->assertSame(1, $refund->reconciliation_attempts);
        $this->assertNotNull($refund->last_reconciled_at);
        $this->assertTrue($refund->next_reconciliation_at->between(now()->addMinutes(29), now()->addMinutes(31)));
    }

    public function test_stale_unmatched_refund_is_escalated_once(): void
    {
        Notification::fake();
        $refund = $this->refund(createdAt: now()->subHours(49));
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        Http::fake([
            'https://api.paystack.co/refund*' => Http::response(['status' => true, 'data' => []]),
        ]);

        $service = app(RefundReconciliationService::class);
        $service->reconcile($refund->id);
        $service->reconcile($refund->id);

        $refund = $refund->fresh();
        $this->assertSame('needs_attention', $refund->status);
        $this->assertSame(1, $refund->reconciliation_attempts);
        $this->assertNull($refund->next_reconciliation_at);
        $this->assertDatabaseHas('refund_events', [
            'refund_id' => $refund->id,
            'action' => 'reconciliation_exhausted',
            'actor_id' => null,
        ]);
        Notification::assertSentTo($refund->user, AutomaticRefundAttentionNotification::class, 1);
        Notification::assertSentTo($admin, AutomaticRefundStaffAlert::class, 1);
        Notification::assertSentTo($staff, AutomaticRefundStaffAlert::class, 1);
    }

    public function test_webhook_settlement_wins_a_reconciliation_race(): void
    {
        Notification::fake();
        $refund = $this->refund();
        Http::fake(function () use ($refund) {
            app(RefundSettlementService::class)->apply(
                $refund,
                $this->providerRefund($refund, status: 'processed'),
            );

            return Http::response(['status' => true, 'data' => []]);
        });

        app(RefundReconciliationService::class)->reconcile($refund->id);

        $refund = $refund->fresh();
        $this->assertSame('processed', $refund->status);
        $this->assertSame(0, $refund->reconciliation_attempts);
        $this->assertNull($refund->next_reconciliation_at);
        Notification::assertSentTo($refund->user, AutomaticRefundProcessedNotification::class, 1);
    }

    public function test_unknown_creation_outcome_schedules_reconciliation(): void
    {
        $refund = $this->refund(status: 'initiating');
        Http::fake(fn () => Http::response([], 503));

        app()->call([new ProcessAutomaticRefund($refund->id), 'handle']);

        $refund = $refund->fresh();
        $this->assertSame('pending', $refund->status);
        $this->assertNotNull($refund->next_reconciliation_at);
        $this->assertTrue($refund->next_reconciliation_at->between(now()->addMinutes(4), now()->addMinutes(6)));
    }

    private function refund(
        string $status = 'pending',
        string $source = 'late_payment',
        mixed $createdAt = null,
        mixed $nextReconciliationAt = null,
        ?string $providerRefundId = null,
    ): Refund {
        $user = $this->user('user');
        $order = Order::query()->create([
            'user_id' => $user->id,
            'number' => 'LMT-REC-'.Str::upper(Str::random(12)),
            'currency' => 'NGN',
            'subtotal' => 2000,
            'grand_total' => 2000,
            'total_amount' => 2000,
            'status' => 'cancelled',
            'payment_status' => 'paid',
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

        $refund = Refund::query()->create([
            'return_request_id' => null,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'user_id' => $user->id,
            'reference' => 'LMT-AUTO-REF-'.Str::upper(Str::random(12)),
            'provider' => 'paystack',
            'method' => 'original_payment',
            'source' => $source,
            'automation_key' => 'test:'.Str::uuid(),
            'status' => $status,
            'currency' => 'NGN',
            'amount' => 2000,
            'amount_minor' => 200000,
            'provider_refund_id' => $providerRefundId,
            'reason' => 'Automatic late payment refund.',
            'next_reconciliation_at' => $nextReconciliationAt,
        ]);

        if ($createdAt) {
            $refund->timestamps = false;
            $refund->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
            $refund->timestamps = true;
        }

        return $refund->fresh(['payment', 'order', 'user']);
    }

    /** @return array<string, mixed> */
    private function providerRefund(Refund $refund, string $status): array
    {
        return [
            'id' => (int) ($refund->provider_refund_id ?? 900001),
            'transaction' => ['reference' => $refund->payment->reference],
            'amount' => $refund->amount_minor,
            'currency' => $refund->currency,
            'status' => $status,
            'refunded_at' => $status === 'processed' ? now()->toIso8601String() : null,
            'createdAt' => now()->toIso8601String(),
        ];
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'username' => $role.'-'.Str::lower(Str::random(10)),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
