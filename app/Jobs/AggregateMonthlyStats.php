<?php

namespace App\Jobs;

use App\Models\Analytics\DailyAggregate;
use App\Models\Analytics\MonthlyAggregate;
use App\Services\Analytics\AnalyticsCache;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class AggregateMonthlyStats implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $year = null,
        public readonly ?int $month = null,
    ) {
        $this->onQueue('analytics');
    }

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function middleware(): array
    {
        $date = $this->targetMonth();

        return [
            (new WithoutOverlapping("analytics:aggregate:monthly:{$date->format('Y-m')}"))
                ->releaseAfter(120)
                ->expireAfter(3600),
        ];
    }

    public function handle(AnalyticsCache $cache): void
    {
        $date = $this->targetMonth();

        if ($this->aggregateMonth($date->year, $date->month)) {
            $cache->invalidate();
        }
    }

    private function aggregateMonth(int $year, int $month): bool
    {
        $from = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $to = $from->copy()->endOfMonth();

        $rows = DailyAggregate::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();

        if ($rows->isEmpty()) {
            return false;
        }

        $orders = (int) $rows->sum('orders_placed');
        $revenue = (float) $rows->sum('orders_revenue');
        $aov = $orders > 0 ? round($revenue / $orders, 2) : 0;

        $totalChkStart = (int) $rows->sum('checkouts_started');
        $totalChkDone = (int) $rows->sum('checkouts_completed');
        $totalVisits = (int) $rows->sum('visits');

        $cartAbandonment = $totalChkStart > 0
            ? round((($totalChkStart - $totalChkDone) / $totalChkStart) * 100, 2)
            : 0;

        $conversionRate = $totalVisits > 0
            ? round(($orders / $totalVisits) * 100, 2)
            : 0;

        MonthlyAggregate::updateOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'visits' => $totalVisits,
                'unique_visitors' => (int) $rows->sum('unique_visitors'),
                'page_views' => (int) $rows->sum('page_views'),
                'product_views' => (int) $rows->sum('product_views'),
                'add_to_carts' => (int) $rows->sum('add_to_carts'),
                'checkouts_started' => $totalChkStart,
                'checkouts_completed' => $totalChkDone,
                'orders_placed' => $orders,
                'orders_revenue' => $revenue,
                'new_users' => (int) $rows->sum('new_users'),
                'returning_users' => (int) $rows->sum('returning_users'),
                'avg_order_value' => $aov,
                'cart_abandonment_rate' => $cartAbandonment,
                'conversion_rate' => $conversionRate,
            ]
        );

        return true;
    }

    private function targetMonth(): Carbon
    {
        return Carbon::parse($this->year && $this->month
            ? "{$this->year}-{$this->month}-01"
            : now()->subMonth()->startOfMonth()
        );
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
