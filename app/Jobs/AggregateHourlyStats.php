<?php

namespace App\Jobs;

use App\Models\Analytics\CartEvent;
use App\Models\Analytics\HourlyAggregate;
use App\Models\Analytics\OrderEvent;
use App\Models\Analytics\PageView;
use App\Models\Analytics\ProductView;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AggregateHourlyStats implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct()
    {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        // Always re-aggregate the last 2 hours to capture any late-arriving events.
        foreach ([now()->subHour()->startOfHour(), now()->startOfHour()] as $hourAt) {
            $this->aggregateHour($hourAt);
        }
    }

    private function aggregateHour(Carbon $hourAt): void
    {
        $from = $hourAt->copy()->startOfHour();
        $to = $hourAt->copy()->endOfHour();

        $visits = PageView::whereBetween('created_at', [$from, $to])->count();
        $unique = PageView::whereBetween('created_at', [$from, $to])->distinct('session_id')->count('session_id');
        $pvs = ProductView::whereBetween('created_at', [$from, $to])->count();
        $atc = CartEvent::where('event_type', 'add')->whereBetween('created_at', [$from, $to])->count();
        $chkStart = CartEvent::where('event_type', 'checkout_start')->whereBetween('created_at', [$from, $to])->count();
        $chkDone = CartEvent::where('event_type', 'checkout_complete')->whereBetween('created_at', [$from, $to])->count();
        $orders = OrderEvent::where('event_type', 'placed')->whereBetween('created_at', [$from, $to])->count();
        $revenue = OrderEvent::where('event_type', 'placed')->whereBetween('created_at', [$from, $to])->sum('amount');
        $newUsers = User::whereBetween('created_at', [$from, $to])->count();

        HourlyAggregate::updateOrCreate(
            ['hour_at' => $from],
            [
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
            ]
        );
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
