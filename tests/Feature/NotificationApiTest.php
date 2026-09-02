<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\User;
use App\Notifications\AutomaticRefundAttentionNotification;
use App\Notifications\AutomaticRefundInitiatedNotification;
use App\Notifications\AutomaticRefundProcessedNotification;
use App\Notifications\AutomaticRefundStaffAlert;
use App\Notifications\InventoryReservationExpiredNotification;
use App\Notifications\LoginNotification;
use App\Notifications\PasswordResetOtpNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\Notification\NotificationUnreadCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Cache::flush();
    }

    public function test_customer_can_filter_and_manage_only_their_notification_feed(): void
    {
        $customer = $this->user('user');
        $other = $this->user('user');
        $processed = $this->notification($customer, 'refund.processed');
        $this->notification($customer, 'order.inventory_reservation_expired');
        $this->notification($customer, 'account.login', now());
        $otherNotification = $this->notification($other, 'refund.processed');

        $this->actingAs($customer, 'api')
            ->getJson('/api/v1/notifications?status=unread&type=refund.processed&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $processed->id)
            ->assertJsonPath('data.items.0.event', 'refund.processed')
            ->assertJsonPath('data.items.0.severity', 'info')
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($customer, 'api')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->actingAs($customer, 'api')
            ->patchJson("/api/v1/notifications/{$otherNotification->id}/read")
            ->assertNotFound();

        $this->actingAs($customer, 'api')
            ->patchJson("/api/v1/notifications/{$processed->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $processed->id)
            ->assertJsonPath('message', 'Notification marked as read.');

        $this->actingAs($customer, 'api')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $customer->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_admin_notification_feed_is_limited_to_staff_and_the_current_recipient(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        $staff = $this->user('staff');
        $admin = $this->user('admin');
        $customer = $this->user('user');
        $staffNotification = $this->notification($staff, 'refund.staff_attention_required');
        $adminNotification = $this->notification($admin, 'refund.staff_attention_required');

        $this->actingAs($staff, 'api')
            ->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $staffNotification->id);

        $this->actingAs($staff, 'api')
            ->patchJson("/api/v1/admin/notifications/{$adminNotification->id}/read")
            ->assertNotFound();

        $this->actingAs($customer, 'api')
            ->getJson('/api/v1/admin/notifications')
            ->assertForbidden();
    }

    public function test_database_delivery_invalidates_the_cached_unread_count(): void
    {
        $customer = $this->user('user');
        $counts = app(NotificationUnreadCount::class);

        $this->assertSame(0, $counts->get($customer));
        $customer->notifyNow(
            new InventoryReservationExpiredNotification(100, 'LMT-100'),
            ['database'],
        );

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $counts->get($customer));
    }

    public function test_operational_notifications_are_persistent_but_otp_notifications_are_not(): void
    {
        $persistent = [
            new LoginNotification('127.0.0.1', 'Test Browser'),
            new InventoryReservationExpiredNotification(1, 'LMT-TEST'),
            new AutomaticRefundInitiatedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundProcessedNotification(1, 'LMT-TEST', 'LMT-REF-TEST', '1000.00', 'NGN'),
            new AutomaticRefundAttentionNotification(1, 'LMT-TEST', 'LMT-REF-TEST'),
            new AutomaticRefundStaffAlert(1, 'LMT-TEST', 'LMT-REF-TEST', 'buyer@example.test', 'Provider rejected the refund.'),
        ];

        foreach ($persistent as $notification) {
            $this->assertContains('database', $notification->via($this->user('user')));
            $this->assertSame('sync', $notification->viaConnections()['database']);
            $this->assertSame(config('queue.default'), $notification->viaConnections()['mail']);
            $this->assertSame('notifications', $notification->viaQueues()['database']);
            $this->assertArrayHasKey('event', $notification->toDatabase($this->user('user')));
        }

        $this->assertNotContains('database', (new VerifyEmailNotification('123456'))->via($this->user('user')));
        $this->assertNotContains('database', (new PasswordResetOtpNotification('123456'))->via($this->user('user')));
    }

    private function notification(User $user, string $event, mixed $readAt = null)
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\TestNotification',
            'data' => [
                'event' => $event,
                'title' => 'Test notification',
                'message' => 'A test notification message.',
                'severity' => 'info',
                'action' => null,
                'metadata' => ['source' => 'test'],
            ],
            'read_at' => $readAt,
        ]);
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
