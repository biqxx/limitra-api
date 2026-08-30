<?php

namespace Tests\Unit;

use App\Jobs\AggregateDailyStats;
use App\Jobs\AggregateHourlyStats;
use App\Jobs\AggregateMonthlyStats;
use App\Jobs\InboundMessageJob;
use App\Jobs\ProcessPaymentWebhook;
use App\Notifications\LoginNotification;
use App\Notifications\PasswordResetOtpNotification;
use App\Notifications\VerifyEmailNotification;
use Tests\TestCase;

class QueueRoutingTest extends TestCase
{
    public function test_existing_jobs_are_routed_to_isolated_queues(): void
    {
        $this->assertSame('payments', (new ProcessPaymentWebhook(1))->queue);
        $this->assertSame('ai', (new InboundMessageJob(1))->queue);
        $this->assertSame('analytics', (new AggregateHourlyStats)->queue);
        $this->assertSame('analytics', (new AggregateDailyStats)->queue);
        $this->assertSame('analytics', (new AggregateMonthlyStats)->queue);
    }

    public function test_queued_mail_uses_the_notifications_queue(): void
    {
        $notifications = [
            new VerifyEmailNotification('123456'),
            new PasswordResetOtpNotification('123456'),
            new LoginNotification('127.0.0.1', 'Test Browser'),
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
