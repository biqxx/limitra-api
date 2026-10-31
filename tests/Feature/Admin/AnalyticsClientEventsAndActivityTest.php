<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\RecordAnalyticsEvent;
use App\Models\Admin\AuditEvent;
use App\Models\Analytics\AnalyticsEvent;
use App\Models\Analytics\DailyAggregate;
use App\Models\Privacy\PrivacyConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalyticsClientEventsAndActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);

        $this->admin = User::factory()->admin()->create();
        $this->staff = User::factory()->staff()->create();
        $this->customer = User::factory()->create();
    }

    public function test_client_events_batch_ingestion_and_pii_sanitization(): void
    {
        Queue::fake([RecordAnalyticsEvent::class]);

        $res = $this->postJson('/api/v1/analytics/events', [
            'session_id' => 'sess-client-12345',
            'events' => [
                [
                    'id' => 'ev-client-1',
                    'type' => 'product_view',
                    'page' => '/products/test-item',
                    'product_id' => 42,
                    'properties' => [
                        'source' => 'search',
                        'query' => 'wireless headphones',
                        'password' => 'supersecret',
                        'credit_card' => '4111222233334444',
                    ],
                ],
                [
                    'id' => 'ev-client-2',
                    'type' => 'add_to_cart',
                    'product_id' => 42,
                    'properties' => [
                        'quantity' => 2,
                        'price' => 15000,
                    ],
                ],
                [
                    'id' => 'ev-client-3',
                    'type' => 'search',
                    'page' => '/search?q=smart+watch',
                    'properties' => [
                        'query' => 'smart watch',
                    ],
                ],
            ],
        ]);

        $res->assertOk()
            ->assertJsonPath('data.accepted', 3)
            ->assertJsonPath('data.dropped', 0);

        Queue::assertPushed(RecordAnalyticsEvent::class, 3);
        Queue::assertPushed(RecordAnalyticsEvent::class, function (RecordAnalyticsEvent $job) {
            if ($job->eventId === 'ev-client-1') {
                return ! isset($job->properties['password'])
                    && ! isset($job->properties['credit_card'])
                    && $job->properties['source'] === 'search';
            }

            return true;
        });
    }

    public function test_client_events_deduplication(): void
    {
        Queue::fake([RecordAnalyticsEvent::class]);

        // Pre-existing event in database
        AnalyticsEvent::create([
            'event_id' => 'ev-existing-999',
            'event_type' => 'page_view',
            'session_id' => 'sess-existing',
        ]);

        $res = $this->postJson('/api/v1/analytics/events', [
            'events' => [
                [
                    'id' => 'ev-existing-999', // should be dropped
                    'type' => 'page_view',
                    'page' => '/home',
                ],
                [
                    'id' => 'ev-fresh-111', // should be accepted
                    'type' => 'video_event',
                    'properties' => ['video_id' => 5, 'action' => 'play'],
                ],
            ],
        ]);

        $res->assertOk()
            ->assertJsonPath('data.accepted', 1)
            ->assertJsonPath('data.dropped', 1);

        Queue::assertPushed(RecordAnalyticsEvent::class, 1);
    }

    public function test_client_events_dropped_when_user_opted_out_of_analytics(): void
    {
        Queue::fake([RecordAnalyticsEvent::class]);

        PrivacyConsent::create([
            'user_id' => $this->customer->id,
            'policy_version' => '1.0',
            'essential' => true,
            'analytics' => false,
            'marketing' => false,
            'third_party_sharing' => false,
            'personalized_ads' => false,
            'source' => 'web',
            'consented_at' => now(),
        ]);

        $res = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/analytics/events', [
                'events' => [
                    [
                        'id' => 'ev-optout-1',
                        'type' => 'product_view',
                        'product_id' => 99,
                    ],
                ],
            ]);

        $res->assertOk()
            ->assertJsonPath('data.accepted', 0)
            ->assertJsonPath('data.dropped', 1);

        Queue::assertNothingPushed();
    }

    public function test_date_range_request_accepts_group_by_aliases(): void
    {
        $date = now()->toDateString();
        DailyAggregate::create([
            'date' => $date,
            'visits' => 50,
            'unique_visitors' => 40,
            'orders_placed' => 5,
            'orders_revenue' => 25000,
            'new_users' => 10,
        ]);

        // hour alias
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/analytics/overview?from={$date}&to={$date}&group_by=hour")
            ->assertOk();

        // day alias
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/analytics/overview?from={$date}&to={$date}&group_by=day")
            ->assertOk()
            ->assertJsonPath('data.summary.visits', 50);

        // week alias
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/analytics/overview?from={$date}&to={$date}&group_by=week")
            ->assertOk();

        // month alias
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/analytics/overview?from={$date}&to={$date}&group_by=month")
            ->assertOk();
    }

    public function test_admin_can_view_and_filter_audit_activity_log(): void
    {
        $event1 = AuditEvent::create([
            'actor_id' => $this->admin->id,
            'action' => 'user.suspended',
            'subject_type' => User::class,
            'subject_id' => $this->customer->id,
            'reason' => 'Fraud investigation',
            'before_values' => ['status' => 'active'],
            'after_values' => ['status' => 'suspended'],
            'request_id' => 'req-audit-1',
        ]);

        $event2 = AuditEvent::create([
            'actor_id' => $this->staff->id,
            'action' => 'inventory.adjusted',
            'reason' => 'Physical stock count discrepancy',
            'before_values' => ['stock' => 10],
            'after_values' => ['stock' => 15],
            'request_id' => 'req-audit-2',
        ]);

        // Customer cannot access audit log
        $this->actingAs($this->customer, 'api')
            ->getJson('/api/v1/admin/activity')
            ->assertForbidden();

        // Admin can list activity
        $listRes = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/activity');

        $listRes->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        // Filter by action
        $filterRes = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/admin/activity?action=user.suspended');

        $filterRes->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $event1->id)
            ->assertJsonPath('data.items.0.actor.username', $this->admin->username);

        // Show single event
        $showRes = $this->actingAs($this->admin, 'api')
            ->getJson("/api/v1/admin/activity/{$event1->id}");

        $showRes->assertOk()
            ->assertJsonPath('data.id', $event1->id)
            ->assertJsonPath('data.action', 'user.suspended')
            ->assertJsonPath('data.reason', 'Fraud investigation')
            ->assertJsonPath('data.before.status', 'active')
            ->assertJsonPath('data.after.status', 'suspended');
    }
}
