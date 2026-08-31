<?php

namespace Tests\Unit;

use App\Jobs\AggregateDailyStats;
use App\Jobs\AggregateHourlyStats;
use App\Jobs\AggregateMonthlyStats;
use App\Jobs\InboundMessageJob;
use App\Jobs\ProcessAutomaticRefund;
use App\Jobs\ProcessPaymentWebhook;
use App\Notifications\AutomaticRefundAttentionNotification;
use App\Notifications\AutomaticRefundInitiatedNotification;
use App\Notifications\AutomaticRefundProcessedNotification;
use App\Notifications\AutomaticRefundStaffAlert;
use App\Notifications\InventoryReservationExpiredNotification;
use App\Notifications\LoginNotification;
use App\Notifications\PasswordResetOtpNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class QueueRoutingTest extends TestCase
{
    public function test_existing_jobs_are_routed_to_isolated_queues(): void
    {
        $this->assertSame('payments', (new ProcessPaymentWebhook(1))->queue);
        $this->assertSame('payments', (new ProcessAutomaticRefund(1))->queue);
        $this->assertSame('ai', (new InboundMessageJob(1))->queue);
        $this->assertSame('analytics', (new AggregateHourlyStats)->queue);
        $this->assertSame('analytics', (new AggregateDailyStats)->queue);
        $this->assertSame('analytics', (new AggregateMonthlyStats)->queue);
    }

    public function test_automatic_refunds_use_an_execution_lock(): void
    {
        $middleware = (new ProcessAutomaticRefund(1))->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertGreaterThan(0, $middleware->releaseAfter);
        $this->assertGreaterThan(0, $middleware->expiresAfter);
    }

    public function test_queued_mail_uses_the_notifications_queue(): void
    {
        $notifications = [
            new VerifyEmailNotification('123456'),
            new PasswordResetOtpNotification('123456'),
            new LoginNotification('127.0.0.1', 'Test Browser'),
            new InventoryReservationExpiredNotification(1, 'LMT-TEST'),
            new AutomaticRefundInitiatedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundProcessedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundAttentionNotification(1, 'LMT-TEST', 'LMT-REF-TEST'),
            new AutomaticRefundStaffAlert(1, 'LMT-TEST', 'LMT-REF-TEST', 'buyer@example.test', 'Provider rejected the refund.'),
        ];

        foreach ($notifications as $notification) {
            $this->assertSame('notifications', $notification->viaQueues()['mail']);
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
}
