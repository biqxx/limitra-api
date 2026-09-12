<?php

namespace Tests\Unit;

use App\Jobs\AggregateDailyStats;
use App\Jobs\AggregateHourlyStats;
use App\Jobs\AggregateMonthlyStats;
use App\Jobs\DispatchPendingRefundReconciliations;
use App\Jobs\InboundMessageJob;
use App\Jobs\ProcessAutomaticRefund;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\ReconcileAutomaticRefund;
use App\Jobs\RecordReferralShareEvent;
use App\Jobs\SendPasswordResetOtp;
use App\Jobs\SendReferralInvitation;
use App\Jobs\SendStaffInvitation;
use App\Notifications\AutomaticRefundAttentionNotification;
use App\Notifications\AutomaticRefundInitiatedNotification;
use App\Notifications\AutomaticRefundProcessedNotification;
use App\Notifications\AutomaticRefundStaffAlert;
use App\Notifications\InventoryReservationExpiredNotification;
use App\Notifications\LoginNotification;
use App\Notifications\ReferralRewardEarnedNotification;
use App\Notifications\ReferralRewardReversedNotification;
use App\Notifications\SupportTicketCustomerNotification;
use App\Notifications\SupportTicketStaffNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class QueueRoutingTest extends TestCase
{
    public function test_existing_jobs_are_routed_to_isolated_queues(): void
    {
        $this->assertSame('payments', (new ProcessPaymentWebhook(1))->queue);
        $this->assertSame('payments', (new ProcessAutomaticRefund(1))->queue);
        $this->assertSame('payments', (new ReconcileAutomaticRefund(1))->queue);
        $this->assertSame('maintenance', (new DispatchPendingRefundReconciliations)->queue);
        $this->assertSame('ai', (new InboundMessageJob(1))->queue);
        $this->assertSame('analytics', (new AggregateHourlyStats)->queue);
        $this->assertSame('analytics', (new AggregateDailyStats)->queue);
        $this->assertSame('analytics', (new AggregateMonthlyStats)->queue);
        $this->assertSame('analytics', (new RecordReferralShareEvent(
            'event-id', 1, 1, 'copy', 'url-hash', '/ref/TEST', null, null, now()->toISOString(),
        ))->queue);
        $this->assertSame('notifications', (new SendReferralInvitation(1))->queue);
        $this->assertSame('notifications', (new SendStaffInvitation(1, 1))->queue);
        $this->assertSame('notifications', (new SendPasswordResetOtp(1, null, 1))->queue);
    }

    public function test_automatic_refunds_use_an_execution_lock(): void
    {
        foreach ([new ProcessAutomaticRefund(1), new ReconcileAutomaticRefund(1)] as $job) {
            $middleware = $job->middleware()[0];

            $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
            $this->assertGreaterThan(0, $middleware->releaseAfter);
            $this->assertGreaterThan(0, $middleware->expiresAfter);
            $this->assertTrue($middleware->shareKey);
        }
    }

    public function test_queued_mail_uses_the_notifications_queue(): void
    {
        $notifications = [
            new VerifyEmailNotification('123456'),
            new ReferralRewardEarnedNotification(700000, 'NGN'),
            new ReferralRewardReversedNotification(700000, 'NGN'),
            new LoginNotification('127.0.0.1', 'Test Browser'),
            new ReferralRewardEarnedNotification(700000, 'NGN'),
            new ReferralRewardReversedNotification(700000, 'NGN'),
            new InventoryReservationExpiredNotification(1, 'LMT-TEST'),
            new AutomaticRefundInitiatedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundProcessedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundAttentionNotification(1, 'LMT-TEST', 'LMT-REF-TEST'),
            new AutomaticRefundStaffAlert(1, 'LMT-TEST', 'LMT-REF-TEST', 'buyer@example.test', 'Provider rejected the refund.'),
            new SupportTicketCustomerNotification(1, 'SUP-TEST', 'Test ticket', 'open', 'reply', 'Test reply.'),
            new SupportTicketStaffNotification(1, 'SUP-TEST', 'Test ticket', 'Test Customer', 'created'),
        ];

        foreach ($notifications as $notification) {
            $this->assertSame('notifications', $notification->viaQueues()['mail']);
        }
    }

    public function test_persistent_notifications_store_immediately_and_queue_mail_on_redis(): void
    {
        $notifications = [
            new LoginNotification('127.0.0.1', 'Test Browser'),
            new InventoryReservationExpiredNotification(1, 'LMT-TEST'),
            new AutomaticRefundInitiatedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundProcessedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundAttentionNotification(1, 'LMT-TEST', 'LMT-REF-TEST'),
            new AutomaticRefundStaffAlert(1, 'LMT-TEST', 'LMT-REF-TEST', 'buyer@example.test', 'Provider rejected the refund.'),
            new SupportTicketCustomerNotification(1, 'SUP-TEST', 'Test ticket', 'open', 'reply', 'Test reply.'),
            new SupportTicketStaffNotification(1, 'SUP-TEST', 'Test ticket', 'Test Customer', 'created'),
        ];

        foreach ($notifications as $notification) {
            $this->assertSame('sync', $notification->viaConnections()['database']);
            $this->assertSame(config('queue.default'), $notification->viaConnections()['mail']);
            $this->assertSame('notifications', $notification->viaQueues()['database']);
        }
    }

    public function test_horizon_has_an_independent_supervisor_for_each_queue(): void
    {
        $queues = [
            'supervisor-payments' => 'payments',
            'supervisor-notifications' => 'notifications',
            'supervisor-ai' => 'ai',
            'supervisor-analytics' => 'analytics',
            'supervisor-maintenance' => 'maintenance',
            'supervisor-default' => 'default',
        ];

        foreach ($queues as $supervisor => $queue) {
            $this->assertSame([$queue], config("horizon.defaults.{$supervisor}.queue"));
            $this->assertArrayHasKey($supervisor, config('horizon.environments.testing'));
        }
    }

    public function test_pending_refund_reconciliation_runs_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'maintenance:pending-refund-reconciliation');

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
