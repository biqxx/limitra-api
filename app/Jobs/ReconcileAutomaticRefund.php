<?php

namespace App\Jobs;

use App\Services\Payment\RefundReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ReconcileAutomaticRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly int $refundId)
    {
        $this->onQueue('payments');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automatic-refund:'.$this->refundId))
                ->releaseAfter(60)
                ->expireAfter(600)
                ->shared(),
        ];
    }

    public function handle(RefundReconciliationService $reconciliation): void
    {
        $reconciliation->reconcile($this->refundId);
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
