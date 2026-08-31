<?php

namespace App\Services\Analytics;

use App\Models\Analytics\CartEvent;
use App\Models\Analytics\DailyAggregate;
use App\Models\Analytics\HourlyAggregate;
use App\Models\Analytics\MonthlyAggregate;
use App\Models\Analytics\OrderEvent;
use App\Models\Analytics\ProductView;
use App\Models\Analytics\TrafficSource;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    public function __construct(private readonly AnalyticsCache $cache) {}

    public function getOverview(string $from, string $to, string $groupBy): array
    {
        return $this->cache->remember('overview', [$from, $to, $groupBy], function () use ($from, $to, $groupBy) {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();

            $prevFrom = $fromDate->copy()->sub($fromDate->diff($toDate));
            $prevTo = $fromDate->copy()->subSecond();

            $current = $this->sumAggregates($fromDate, $toDate, $groupBy);
            $previous = $this->sumAggregates($prevFrom, $prevTo, $groupBy);

            $visits = $current['visits'];
            $orders = $current['orders_placed'];
            $revenue = $current['orders_revenue'];
            $aov = $orders > 0 ? round($revenue / $orders, 2) : 0;

            return [
                'summary' => [
                    'visits' => $visits,
                    'unique_visitors' => $current['unique_visitors'],
                    'orders' => $orders,
                    'revenue' => $revenue,
                    'aov' => $aov,
                    'conversion_rate' => $this->rate($orders, $visits),
                    'new_users' => $current['new_users'],
                ],
                'trends' => [
                    'visits' => $this->trend($current['visits'], $previous['visits']),
                    'orders' => $this->trend($current['orders_placed'], $previous['orders_placed']),
                    'revenue' => $this->trend($current['orders_revenue'], $previous['orders_revenue']),
                    'new_users' => $this->trend($current['new_users'], $previous['new_users']),
                ],
                'chart' => $this->buildChart($fromDate, $toDate, $groupBy),
            ];
        });
    }

    public function getTrafficStats(string $from, string $to, string $groupBy): array
    {
        return $this->cache->remember('traffic', [$from, $to, $groupBy], function () use ($from, $to, $groupBy) {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();

            $prevFrom = $fromDate->copy()->sub($fromDate->diff($toDate));
            $prevTo = $fromDate->copy()->subSecond();

            $current = $this->sumAggregates($fromDate, $toDate, $groupBy);
            $previous = $this->sumAggregates($prevFrom, $prevTo, $groupBy);

            $sources = TrafficSource::whereBetween('created_at', [$fromDate, $toDate])
                ->selectRaw('source, COUNT(*) as sessions')
                ->groupBy('source')
                ->orderByDesc('sessions')
                ->get();

            return [
                'summary' => [
                    'visits' => $current['visits'],
                    'unique_visitors' => $current['unique_visitors'],
                    'page_views' => $current['page_views'],
                    'product_views' => $current['product_views'],
                ],
                'trends' => [
                    'visits' => $this->trend($current['visits'], $previous['visits']),
                    'unique_visitors' => $this->trend($current['unique_visitors'], $previous['unique_visitors']),
                ],
                'sources' => $sources,
                'chart' => $this->buildChartForField($fromDate, $toDate, $groupBy, 'visits'),
            ];
        });
    }

    public function getSalesStats(string $from, string $to, string $groupBy): array
    {
        return $this->cache->remember('sales', [$from, $to, $groupBy], function () use ($from, $to, $groupBy) {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();

            $prevFrom = $fromDate->copy()->sub($fromDate->diff($toDate));
            $prevTo = $fromDate->copy()->subSecond();

            $current = $this->sumAggregates($fromDate, $toDate, $groupBy);
            $previous = $this->sumAggregates($prevFrom, $prevTo, $groupBy);

            $orders = $current['orders_placed'];
            $revenue = $current['orders_revenue'];
            $aov = $orders > 0 ? round($revenue / $orders, 2) : 0;
            $visits = $current['visits'];
            $revenuePerVis = $visits > 0 ? round($revenue / $visits, 2) : 0;

            // discount_amount column can be added in a future migration; returns 0 until then.
            $discountUsage = 0;

            $refundedOrders = OrderEvent::where('event_type', 'refunded')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->count();

            return [
                'summary' => [
                    'orders' => $orders,
                    'revenue' => $revenue,
                    'aov' => $aov,
                    'revenue_per_visit' => $revenuePerVis,
                    'discount_orders' => $discountUsage,
                    'refunded_orders' => $refundedOrders,
                ],
                'trends' => [
                    'orders' => $this->trend($current['orders_placed'], $previous['orders_placed']),
                    'revenue' => $this->trend($current['orders_revenue'], $previous['orders_revenue']),
                ],
                'chart' => $this->buildRevenueChart($fromDate, $toDate, $groupBy),
            ];
        });
    }

    public function getConversionStats(string $from, string $to, string $groupBy): array
    {
        return $this->cache->remember('conversion', [$from, $to, $groupBy], function () use ($from, $to, $groupBy) {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();

            $agg = $this->sumAggregates($fromDate, $toDate, $groupBy);

            $visits = $agg['visits'];
            $addToCarts = $agg['add_to_carts'];
            $checkoutsStarted = $agg['checkouts_started'];
            $checkoutsComplete = $agg['checkouts_completed'];
            $orders = $agg['orders_placed'];

            $uniqueFavoriters = DB::table('favorites')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->distinct('user_id')
                ->count('user_id');

            $favoritersWhoOrdered = DB::table('favorites')
                ->join('orders', 'favorites.user_id', '=', 'orders.user_id')
                ->whereBetween('favorites.created_at', [$fromDate, $toDate])
                ->whereBetween('orders.created_at', [$fromDate, $toDate])
                ->distinct('favorites.user_id')
                ->count('favorites.user_id');

            $uniqueCarted = CartEvent::where('event_type', 'add')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->distinct('session_id')
                ->count('session_id');

            $cartedWhoOrdered = DB::table('cart_events')
                ->join('order_events', function ($join) use ($fromDate, $toDate) {
                    $join->on('cart_events.session_id', '=', 'order_events.session_id')
                        ->where('order_events.event_type', 'placed')
                        ->whereBetween('order_events.created_at', [$fromDate, $toDate]);
                })
                ->where('cart_events.event_type', 'add')
                ->whereBetween('cart_events.created_at', [$fromDate, $toDate])
                ->distinct('cart_events.session_id')
                ->count('cart_events.session_id');

            return [
                'funnel' => [
                    ['stage' => 'Visits',              'count' => $visits,            'rate' => 100.0],
                    ['stage' => 'Added to Cart',       'count' => $addToCarts,        'rate' => $this->rate($addToCarts, $visits)],
                    ['stage' => 'Checkout Started',    'count' => $checkoutsStarted,  'rate' => $this->rate($checkoutsStarted, $visits)],
                    ['stage' => 'Checkout Completed',  'count' => $checkoutsComplete, 'rate' => $this->rate($checkoutsComplete, $visits)],
                    ['stage' => 'Orders Placed',       'count' => $orders,            'rate' => $this->rate($orders, $visits)],
                ],
                'rates' => [
                    'visit_to_order' => $this->rate($orders, $visits),
                    'cart_to_order' => $this->rate($cartedWhoOrdered, $uniqueCarted),
                    'favourite_to_order' => $this->rate($favoritersWhoOrdered, $uniqueFavoriters),
                    'add_to_cart_rate' => $this->rate($addToCarts, $visits),
                    'checkout_completion_rate' => $this->rate($checkoutsComplete, $checkoutsStarted),
                    'cart_abandonment_rate' => $this->rate($checkoutsStarted - $checkoutsComplete, $checkoutsStarted),
                ],
            ];
        });
    }

    public function getProductStats(string $from, string $to, string $groupBy): array
    {
        return $this->cache->remember('products', [$from, $to, $groupBy], function () use ($from, $to) {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();

            $topSelling = OrderItem::with('product:id,name,price')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$fromDate, $toDate])
                ->where('orders.status', '!=', 'cancelled')
                ->selectRaw('product_id, SUM(quantity) as total_sold, SUM(quantity * price_at_purchase) as total_revenue')
                ->groupBy('product_id')
                ->orderByDesc('total_sold')
                ->limit(10)
                ->get();

            $mostViewed = ProductView::whereBetween('created_at', [$fromDate, $toDate])
                ->selectRaw('product_id, COUNT(*) as view_count')
                ->groupBy('product_id')
                ->orderByDesc('view_count')
                ->limit(10)
                ->with('product:id,name,price')
                ->get();

            $topViewedIds = $mostViewed->pluck('product_id');

            $viewedAndOrdered = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$fromDate, $toDate])
                ->whereIn('order_items.product_id', $topViewedIds)
                ->where('orders.status', '!=', 'cancelled')
                ->selectRaw('order_items.product_id, COUNT(DISTINCT orders.user_id) as buyers')
                ->groupBy('order_items.product_id')
                ->pluck('buyers', 'product_id');

            $productConversion = $mostViewed->map(function ($pv) use ($viewedAndOrdered) {
                return [
                    'product_id' => $pv->product_id,
                    'name' => $pv->product?->name,
                    'views' => $pv->view_count,
                    'buyers' => $viewedAndOrdered[$pv->product_id] ?? 0,
                    'conversion_rate' => $this->rate($viewedAndOrdered[$pv->product_id] ?? 0, $pv->view_count),
                ];
            });

            $wishlistCounts = DB::table('favorites')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->selectRaw('product_id, COUNT(*) as favourites')
                ->groupBy('product_id')
                ->orderByDesc('favourites')
                ->limit(10)
                ->get();

            $lowPerforming = Product::withCount(['orderItems as orders_count' => function ($q) use ($fromDate, $toDate) {
                $q->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->whereBetween('orders.created_at', [$fromDate, $toDate])
                    ->where('orders.status', '!=', 'cancelled');
            }])
                ->whereDoesntHave('orderItems', function ($q) use ($fromDate, $toDate) {
                    $q->join('orders', 'order_items.order_id', '=', 'orders.id')
                        ->whereBetween('orders.created_at', [$fromDate, $toDate])
                        ->where('orders.status', '!=', 'cancelled');
                })
                ->orderBy('orders_count')
                ->limit(10)
                ->get(['id', 'name', 'price', 'stock']);

            return [
                'top_selling' => $topSelling,
                'most_viewed' => $mostViewed,
                'product_conversion' => $productConversion,
                'wishlist_counts' => $wishlistCounts,
                'low_performing' => $lowPerforming,
            ];
        });
    }

    public function getCustomerStats(string $from, string $to, string $groupBy): array
    {
        return $this->cache->remember('customers', [$from, $to, $groupBy], function () use ($from, $to, $groupBy) {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();

            $newUsers = User::whereBetween('created_at', [$fromDate, $toDate])->count();

            $returningUsers = Order::whereBetween('created_at', [$fromDate, $toDate])
                ->where('status', '!=', 'cancelled')
                ->whereHas('user', function ($q) use ($fromDate) {
                    $q->where('created_at', '<', $fromDate);
                })
                ->distinct('user_id')
                ->count('user_id');

            $repeatBuyers = DB::table('orders')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->where('status', '!=', 'cancelled')
                ->selectRaw('user_id, COUNT(*) as order_count')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            $totalBuyers = Order::whereBetween('created_at', [$fromDate, $toDate])
                ->where('status', '!=', 'cancelled')
                ->distinct('user_id')
                ->count('user_id');

            $clv = DB::table('orders')
                ->where('status', '!=', 'cancelled')
                ->selectRaw('user_id, SUM(total_amount) as lifetime_value')
                ->groupBy('user_id')
                ->orderByDesc('lifetime_value')
                ->limit(10)
                ->get();

            return [
                'summary' => [
                    'new_users' => $newUsers,
                    'returning_users' => $returningUsers,
                    'repeat_buyers' => $repeatBuyers,
                    'total_buyers' => $totalBuyers,
                    'repeat_rate' => $this->rate($repeatBuyers, $totalBuyers),
                ],
                'trends' => [
                    'new_users' => $this->trend(
                        $newUsers,
                        User::whereBetween('created_at', [
                            $fromDate->copy()->sub($fromDate->diff($toDate)),
                            $fromDate->copy()->subSecond(),
                        ])->count()
                    ),
                ],
                'top_clv_customers' => $clv,
                'chart' => $this->buildChartForField($fromDate, $toDate, $groupBy, 'new_users'),
            ];
        });
    }

    private function sumAggregates(Carbon $from, Carbon $to, string $groupBy): array
    {
        $blank = [
            'visits' => 0, 'unique_visitors' => 0, 'page_views' => 0,
            'product_views' => 0, 'add_to_carts' => 0, 'checkouts_started' => 0,
            'checkouts_completed' => 0, 'orders_placed' => 0, 'orders_revenue' => 0,
            'new_users' => 0,
        ];

        $rows = match ($groupBy) {
            'hourly' => HourlyAggregate::whereBetween('hour_at', [$from, $to])->get(),
            'monthly' => $this->monthlyAggregatesQuery($from, $to)->get(),
            default => $this->dailyAggregatesQuery($from, $to)->get(),
        };

        if ($rows->isEmpty()) {
            return $blank;
        }

        return [
            'visits' => (int) $rows->sum('visits'),
            'unique_visitors' => (int) $rows->sum('unique_visitors'),
            'page_views' => (int) $rows->sum('page_views'),
            'product_views' => (int) $rows->sum('product_views'),
            'add_to_carts' => (int) $rows->sum('add_to_carts'),
            'checkouts_started' => (int) $rows->sum('checkouts_started'),
            'checkouts_completed' => (int) $rows->sum('checkouts_completed'),
            'orders_placed' => (int) $rows->sum('orders_placed'),
            'orders_revenue' => (float) $rows->sum('orders_revenue'),
            'new_users' => (int) $rows->sum('new_users'),
        ];
    }

    private function buildChart(Carbon $from, Carbon $to, string $groupBy): array
    {
        return match ($groupBy) {
            'hourly' => HourlyAggregate::whereBetween('hour_at', [$from, $to])
                ->orderBy('hour_at')
                ->get(['hour_at as period', 'visits', 'orders_placed', 'orders_revenue'])
                ->toArray(),
            'monthly' => $this->monthlyAggregatesQuery($from, $to)
                ->orderBy('year')->orderBy('month')
                ->get(['year', 'month', 'visits', 'orders_placed', 'orders_revenue'])
                ->map(fn ($r) => array_merge($r->toArray(), ['period' => sprintf('%04d-%02d', $r->year, $r->month)]))
                ->toArray(),
            default => $this->dailyAggregatesQuery($from, $to)
                ->orderBy('date')
                ->get(['date as period', 'visits', 'orders_placed', 'orders_revenue'])
                ->toArray(),
        };
    }

    private function buildChartForField(Carbon $from, Carbon $to, string $groupBy, string $field): array
    {
        return match ($groupBy) {
            'hourly' => HourlyAggregate::whereBetween('hour_at', [$from, $to])
                ->orderBy('hour_at')
                ->get(['hour_at as period', $field])
                ->toArray(),
            'monthly' => $this->monthlyAggregatesQuery($from, $to)
                ->orderBy('year')->orderBy('month')
                ->get(['year', 'month', $field])
                ->map(fn ($r) => ['period' => sprintf('%04d-%02d', $r->year, $r->month), $field => $r->$field])
                ->toArray(),
            default => $this->dailyAggregatesQuery($from, $to)
                ->orderBy('date')
                ->get(['date as period', $field])
                ->toArray(),
        };
    }

    private function buildRevenueChart(Carbon $from, Carbon $to, string $groupBy): array
    {
        return match ($groupBy) {
            'hourly' => HourlyAggregate::whereBetween('hour_at', [$from, $to])
                ->orderBy('hour_at')
                ->get(['hour_at as period', 'orders_placed', 'orders_revenue'])
                ->toArray(),
            'monthly' => $this->monthlyAggregatesQuery($from, $to)
                ->orderBy('year')->orderBy('month')
                ->get(['year', 'month', 'orders_placed', 'orders_revenue'])
                ->map(fn ($r) => array_merge($r->toArray(), ['period' => sprintf('%04d-%02d', $r->year, $r->month)]))
                ->toArray(),
            default => $this->dailyAggregatesQuery($from, $to)
                ->orderBy('date')
                ->get(['date as period', 'orders_placed', 'orders_revenue'])
                ->toArray(),
        };
    }

    private function rate(int|float $numerator, int|float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function trend(int|float $current, int|float $previous): array
    {
        $change = $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : ($current > 0 ? 100.0 : 0.0);

        return [
            'current' => $current,
            'previous' => $previous,
            'change_percent' => $change,
            'direction' => $change >= 0 ? 'up' : 'down',
        ];
    }

    private function monthlyAggregatesQuery(Carbon $from, Carbon $to): Builder
    {
        return MonthlyAggregate::query()
            ->where(function (Builder $query) use ($from) {
                $query->where('year', '>', $from->year)
                    ->orWhere(function (Builder $query) use ($from) {
                        $query->where('year', $from->year)
                            ->where('month', '>=', $from->month);
                    });
            })
            ->where(function (Builder $query) use ($to) {
                $query->where('year', '<', $to->year)
                    ->orWhere(function (Builder $query) use ($to) {
                        $query->where('year', $to->year)
                            ->where('month', '<=', $to->month);
                    });
            });
    }

    private function dailyAggregatesQuery(Carbon $from, Carbon $to): Builder
    {
        return DailyAggregate::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString());
    }
}
