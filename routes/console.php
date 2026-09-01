<?php

use App\Jobs\AggregateDailyStats;
use App\Jobs\AggregateHourlyStats;
use App\Jobs\AggregateMonthlyStats;
use App\Jobs\DispatchPendingRefundReconciliations;
use App\Jobs\PruneExpiredAuthSessions;
use App\Jobs\PruneExpiredCheckoutQuotes;
use App\Jobs\PruneRawAnalytics;
use App\Jobs\ReleaseExpiredInventoryReservations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aggregate raw events into hourly buckets — runs every hour.
Schedule::job(new AggregateHourlyStats)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('analytics:hourly');

// Aggregate the previous day into a daily summary — runs at 00:05 each day.
Schedule::job(new AggregateDailyStats)
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('analytics:daily');

// Roll daily summaries into a monthly summary — runs at 00:15 on the 1st of each month.
Schedule::job(new AggregateMonthlyStats)
    ->monthlyOn(1, '00:15')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('analytics:monthly');

Schedule::job(new PruneExpiredAuthSessions)
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('maintenance:auth-sessions');

Schedule::job(new PruneExpiredCheckoutQuotes)
    ->dailyAt('01:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('maintenance:checkout-quotes');

Schedule::job(new PruneRawAnalytics)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('maintenance:raw-analytics');

Schedule::job(new ReleaseExpiredInventoryReservations)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('maintenance:inventory-reservations');

Schedule::job(new DispatchPendingRefundReconciliations)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('maintenance:pending-refund-reconciliation');
