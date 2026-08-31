<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\RecordAnalyticsEvent;
use App\Services\Analytics\AnalyticsTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalyticsQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_analytics_are_dispatched_as_a_serializable_queue_job(): void
    {
        Queue::fake();

        $request = Request::create(
            'https://limitra.test/api/products/42?utm_source=Newsletter&utm_medium=email',
            'GET',
            server: [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_REFERER' => 'https://example.test/campaign',
                'HTTP_USER_AGENT' => 'Limitra Test Browser',
                'HTTP_X_SESSION_ID' => 'session-123',
            ],
        );

        $response = (new TrackAnalytics)->handle($request, fn () => response('ok'));

        $this->assertSame('session-123', $response->headers->get('X-Session-ID'));

        Queue::assertPushed(RecordAnalyticsEvent::class, function (RecordAnalyticsEvent $job): bool {
            $serialized = serialize($job);

            $this->assertSame('analytics', $job->queue);
            $this->assertSame(RecordAnalyticsEvent::PAGE_VIEW, $job->kind);
            $this->assertSame('session-123', $job->sessionId);
            $this->assertSame('newsletter', mb_strtolower($job->context['utm_source']));
            $this->assertStringNotContainsString(Request::class, $serialized);

            return true;
        });
    }

    public function test_analytics_job_retries_do_not_duplicate_an_event(): void
    {
        $job = new RecordAnalyticsEvent(
            RecordAnalyticsEvent::PAGE_VIEW,
            'page_view',
            'session-456',
            null,
            ['url' => 'https://limitra.test/products/42'],
            [
                'ip_address' => '203.0.113.10',
                'user_agent' => 'Limitra Test Browser',
                'referrer' => 'https://google.com/search',
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
            ],
            '01994f70-b3d7-7c31-92a6-13b697b7191a',
            '2026-08-31T10:15:00+00:00',
        );

        $tracking = app(AnalyticsTrackingService::class);
        $job->handle($tracking);
        $job->handle($tracking);

        $this->assertDatabaseCount('analytics_events', 1);
        $this->assertDatabaseCount('page_views', 1);
        $this->assertDatabaseCount('traffic_sources', 1);
        $this->assertDatabaseHas('analytics_events', [
            'event_id' => '01994f70-b3d7-7c31-92a6-13b697b7191a',
            'session_id' => 'session-456',
        ]);
        $this->assertDatabaseHas('page_views', [
            'session_id' => 'session-456',
            'source' => 'google',
        ]);
    }

    public function test_all_analytics_event_factories_use_the_analytics_queue(): void
    {
        $request = Request::create('https://limitra.test/api/test');
        $jobs = [
            RecordAnalyticsEvent::pageView($request, 'session', null),
            RecordAnalyticsEvent::productView($request, 'session', 1, 42),
            RecordAnalyticsEvent::cartEvent($request, 'add', 'session', 1, 2, 42, 3, 10.50),
            RecordAnalyticsEvent::orderEvent($request, 'placed', 'session', 1, 10, 31.50),
        ];

        foreach ($jobs as $job) {
            $this->assertSame('analytics', $job->queue);
            $this->assertNotEmpty($job->eventId);
            $this->assertNotEmpty($job->occurredAt);
        }
    }
}
