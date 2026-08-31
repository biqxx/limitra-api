<?php

namespace App\Jobs;

use App\Services\Order\InventoryReservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ReleaseExpiredInventoryReservations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('maintenance:release-expired-inventory'))
                ->releaseAfter(60)
                ->expireAfter(300),
        ];
    }

    public function handle(InventoryReservationService $reservations): void
    {
        $reservations->releaseExpired();
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
