<?php

namespace App\Jobs;

use App\Services\Maintenance\MaintenancePruningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PruneRawAnalytics implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('maintenance:prune-raw-analytics'))
                ->releaseAfter(300)
                ->expireAfter(3600),
        ];
    }

    public function handle(MaintenancePruningService $pruner): void
    {
        $pruner->pruneRawAnalytics();
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
