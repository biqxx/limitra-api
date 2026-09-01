<?php

namespace App\Jobs;

use App\Services\Payment\RefundReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class DispatchPendingRefundReconciliations implements ShouldQueue
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
            (new WithoutOverlapping('maintenance:pending-refund-reconciliation'))
                ->releaseAfter(60)
                ->expireAfter(300),
        ];
    }

    public function handle(RefundReconciliationService $reconciliation): void
    {
        $reconciliation->dispatchDue();
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
