<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Notification\NotificationPreference;
use App\Models\User;
use App\Notifications\AutomaticRefundInitiatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NotificationPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Cache::flush();
    }

    public function test_customer_receives_database_backed_notification_defaults(): void
    {
        $customer = $this->user('user');

        $response = $this->actingAs($customer, 'api')
            ->getJson('/api/v1/notification-preferences')
            ->assertOk()
            ->assertJsonCount(6, 'data.events')
            ->assertJsonFragment([
                'event' => 'refund.initiated',
                'label' => 'Refund started',
                'category' => 'refunds',
            ]);

        $event = collect($response->json('data.events'))->firstWhere('event', 'refund.initiated');
        $this->assertTrue($event['channels']['in_app']['enabled']);
        $this->assertTrue($event['channels']['in_app']['required']);
        $this->assertTrue($event['channels']['email']['enabled']);
        $this->assertFalse($event['channels']['email']['required']);
    }

    public function test_customer_can_disable_email_but_not_required_transactional_alerts(): void
    {
        $customer = $this->user('user');
        $other = $this->user('user');
        $notification = $this->refundNotification();

        $this->actingAs($customer, 'api')
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [[
                    'event' => 'refund.initiated',
                    'channels' => [
                        'in_app' => true,
                        'email' => false,
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Notification preferences updated.');

        $this->assertSame(['database'], $notification->via($customer));
        $this->assertSame(['database', 'mail'], $notification->via($other));
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $customer->id,
            'event' => 'refund.initiated',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        $this->actingAs($customer, 'api')
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [[
                    'event' => 'refund.initiated',
                    'channels' => ['in_app' => false],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preferences.0.channels.in_app');

        $this->assertSame(['database'], $notification->via($customer));
    }

    public function test_replacing_preferences_clears_omitted_overrides_and_rejects_unknown_channels(): void
    {
        $customer = $this->user('user');
        NotificationPreference::query()->create([
            'user_id' => $customer->id,
            'event' => 'refund.initiated',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        $this->actingAs($customer, 'api')
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [[
                    'event' => 'account.login',
                    'channels' => ['push' => true],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preferences.0.channels.push');

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $customer->id,
            'event' => 'refund.initiated',
        ]);

        $this->actingAs($customer, 'api')
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [[
                    'event' => 'account.login',
                    'channels' => ['in_app' => true, 'email' => true],
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $customer->id,
            'event' => 'refund.initiated',
        ]);
        $this->assertDatabaseCount('notification_preferences', 2);
    }

    public function test_admin_can_change_defaults_but_cannot_remove_required_channels(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user('staff');
        $customer = $this->user('user');
        $notification = $this->refundNotification();

        $this->assertSame(['database', 'mail'], $notification->via($customer));

        $this->actingAs($staff, 'api')
            ->getJson('/api/v1/admin/notification-settings')
            ->assertForbidden();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/notification-settings')
            ->assertOk()
            ->assertJsonCount(6, 'data.events');

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/notification-settings/refund.initiated', [
                'default_channels' => ['in_app'],
            ])
            ->assertOk()
            ->assertJsonPath('data.default_channels.0', 'in_app');

        $this->assertSame(['database'], $notification->via($customer));

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/notification-settings/refund.initiated', [
                'default_channels' => ['email'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_channels');

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/notification-settings/unknown.event', [
                'default_channels' => ['in_app'],
            ])
            ->assertNotFound();
    }

    private function refundNotification(): AutomaticRefundInitiatedNotification
    {
        return new AutomaticRefundInitiatedNotification(
            1,
            'LMT-TEST',
            'LMT-REF-TEST',
            '1000.00',
            'NGN',
        );
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
