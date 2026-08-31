<?php

namespace App\Jobs;

use App\Models\Analytics\CartEvent;
use App\Models\Analytics\DailyAggregate;
use App\Models\Analytics\OrderEvent;
use App\Models\Analytics\PageView;
use App\Models\Analytics\ProductView;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\Analytics\AnalyticsCache;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class AggregateDailyStats implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $date = null)
    {
        $this->onQueue('analytics');
    }

    public int $tries = 3;

    public array $backoff = [5, 30, 60];

    public function middleware(): array
    {
        $date = Carbon::parse($this->date ?? now()->subDay()->toDateString())->toDateString();

        return [
            (new WithoutOverlapping("analytics:aggregate:daily:{$date}"))
                ->releaseAfter(60)
                ->expireAfter(1800),
        ];
    }

    public function handle(AnalyticsCache $cache): void
    {
        // Yesterday by default; injected date allows manual backfill.
        $date = Carbon::parse($this->date ?? now()->subDay()->toDateString());
        $this->aggregateDay($date);
        $cache->invalidate();
    }

    private function aggregateDay(Carbon $date): void
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        $visits = PageView::whereBetween('created_at', [$from, $to])->count();
        $unique = PageView::whereBetween('created_at', [$from, $to])->distinct('session_id')->count('session_id');
        $pvs = ProductView::whereBetween('created_at', [$from, $to])->count();
        $atc = CartEvent::where('event_type', 'add')->whereBetween('created_at', [$from, $to])->count();
        $chkStart = CartEvent::where('event_type', 'checkout_start')->whereBetween('created_at', [$from, $to])->count();
        $chkDone = CartEvent::where('event_type', 'checkout_complete')->whereBetween('created_at', [$from, $to])->count();
        $orders = OrderEvent::where('event_type', 'placed')->whereBetween('created_at', [$from, $to])->count();
        $revenue = (float) OrderEvent::where('event_type', 'placed')->whereBetween('created_at', [$from, $to])->sum('amount');
        $aov = $orders > 0 ? round($revenue / $orders, 2) : 0;

        $newUsers = User::whereBetween('created_at', [$from, $to])->count();

        $returningUsers = Order::whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->whereHas('user', fn ($q) => $q->where('created_at', '<', $from))
            ->distinct('user_id')
            ->count('user_id');

        $cartAbandonment = $chkStart > 0 ? round((($chkStart - $chkDone) / $chkStart) * 100, 2) : 0;
        $conversionRate = $visits > 0 ? round(($orders / $visits) * 100, 2) : 0;

        $aggregate = DailyAggregate::query()
            ->whereDate('date', $date->toDateString())
            ->firstOrNew();

        $aggregate->fill([
            'date' => $date->toDateString(),
            'visits' => $visits,
            'unique_visitors' => $unique,
            'page_views' => $visits,
            'product_views' => $pvs,
            'add_to_carts' => $atc,
            'checkouts_started' => $chkStart,
            'checkouts_completed' => $chkDone,
            'orders_placed' => $orders,
            'orders_revenue' => $revenue,
            'new_users' => $newUsers,
            'returning_users' => $returningUsers,
            'avg_order_value' => $aov,
            'cart_abandonment_rate' => $cartAbandonment,
            'conversion_rate' => $conversionRate,
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
