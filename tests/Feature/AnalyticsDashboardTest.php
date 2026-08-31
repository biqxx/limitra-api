<?php

namespace Tests\Feature;

use App\Jobs\AggregateDailyStats;
use App\Jobs\AggregateHourlyStats;
use App\Jobs\AggregateMonthlyStats;
use App\Models\Analytics\DailyAggregate;
use App\Models\Analytics\MonthlyAggregate;
use App\Models\Analytics\PageView;
use App\Models\User;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        Cache::flush();
    }

    public function test_staff_can_read_dashboard_data_but_customers_cannot(): void
    {
        $date = now()->toDateString();

        DailyAggregate::create([
            'date' => $date,
            'visits' => 20,
            'unique_visitors' => 15,
            'orders_placed' => 4,
            'orders_revenue' => 12000,
            'new_users' => 3,
        ]);

        $staff = $this->user('staff');
        $customer = $this->user('user');

        $this->actingAs($staff, 'api')
            ->getJson("/api/v1/analytics/overview?from={$date}&to={$date}&group_by=daily")
            ->assertOk()
            ->assertJsonPath('data.summary.visits', 20)
            ->assertJsonPath('data.summary.orders', 4)
            ->assertJsonPath('data.summary.revenue', 12000)
            ->assertJsonPath('data.summary.conversion_rate', 20);

        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/analytics/overview?from={$date}&to={$date}")
            ->assertForbidden();
    }

    public function test_dashboard_date_range_is_validated(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/analytics/overview?from=2026-08-31&to=2026-08-01&group_by=weekly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to', 'group_by']);
    }

    public function test_staff_can_read_every_dashboard_section(): void
    {
        $staff = $this->user('staff');
        $date = now()->toDateString();

        foreach (['overview', 'traffic', 'sales', 'conversion', 'products', 'customers'] as $section) {
            $this->actingAs($staff, 'api')
                ->getJson("/api/v1/analytics/{$section}?from={$date}&to={$date}")
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    public function test_daily_aggregation_refreshes_cached_dashboard_data(): void
    {
        $date = now()->toDateString();

        DailyAggregate::create([
            'date' => $date,
            'visits' => 10,
            'unique_visitors' => 10,
        ]);

        $analytics = app(AnalyticsService::class);
        $cache = app(AnalyticsCache::class);
        $first = $analytics->getOverview($date, $date, 'daily');

        PageView::insert([
            ['session_id' => 'session-a', 'url' => '/', 'created_at' => now()],
            ['session_id' => 'session-a', 'url' => '/products', 'created_at' => now()],
            ['session_id' => 'session-b', 'url' => '/cart', 'created_at' => now()],
        ]);

        DailyAggregate::where('date', $date)->update(['visits' => 99]);

        $this->assertSame(10, $first['summary']['visits']);
        $this->assertSame(10, $analytics->getOverview($date, $date, 'daily')['summary']['visits']);

        $version = $cache->version();
        app()->call([new AggregateDailyStats($date), 'handle']);

        $this->assertNotSame($version, $cache->version());
        $this->assertSame(3, $analytics->getOverview($date, $date, 'daily')['summary']['visits']);

        $aggregate = DailyAggregate::query()->whereDate('date', $date)->firstOrFail();

        $this->assertSame(3, $aggregate->visits);
        $this->assertSame(2, $aggregate->unique_visitors);
    }

    public function test_monthly_queries_work_without_database_specific_date_sql(): void
    {
        MonthlyAggregate::create([
            'year' => 2026,
            'month' => 8,
            'visits' => 25,
            'unique_visitors' => 18,
        ]);

        $result = app(AnalyticsService::class)->getOverview('2026-08-01', '2026-08-31', 'monthly');

        $this->assertSame(25, $result['summary']['visits']);
        $this->assertSame('2026-08', $result['chart'][0]['period']);
    }

    public function test_monthly_aggregation_rolls_up_daily_rows_and_invalidates_cache(): void
    {
        DailyAggregate::create([
            'date' => '2026-07-01',
            'visits' => 20,
            'orders_placed' => 2,
            'orders_revenue' => 5000,
        ]);
        DailyAggregate::create([
            'date' => '2026-07-31',
            'visits' => 30,
            'orders_placed' => 3,
            'orders_revenue' => 7500,
        ]);

        $cache = app(AnalyticsCache::class);
        $version = $cache->version();

        app()->call([new AggregateMonthlyStats(2026, 7), 'handle']);

        $aggregate = MonthlyAggregate::query()
            ->where('year', 2026)
            ->where('month', 7)
            ->firstOrFail();

        $this->assertNotSame($version, $cache->version());
        $this->assertSame(50, $aggregate->visits);
        $this->assertSame(5, $aggregate->orders_placed);
        $this->assertSame('12500.00', $aggregate->orders_revenue);
    }

    public function test_aggregation_jobs_use_analytics_queue_and_execution_locks(): void
    {
        $jobs = [
            new AggregateHourlyStats,
            new AggregateDailyStats('2026-08-30'),
            new AggregateMonthlyStats(2026, 7),
        ];

        foreach ($jobs as $job) {
            $this->assertSame('analytics', $job->queue);

            $middleware = $job->middleware()[0];

            $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
            $this->assertGreaterThan(0, $middleware->releaseAfter);
            $this->assertGreaterThan(0, $middleware->expiresAfter);

            $lock = Cache::lock($middleware->getLockKey($job), 10);
            $this->assertTrue($lock->get());

            $ran = false;

            try {
                $middleware->handle($job, function () use (&$ran): void {
                    $ran = true;
                });
            } finally {
                $lock->release();
            }

            $this->assertFalse($ran);
        }
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'username' => $role.'-'.Str::lower(Str::random(8)),
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
