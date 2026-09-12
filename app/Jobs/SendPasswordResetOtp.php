<?php

namespace App\Jobs;

use App\Services\Auth\PasswordResetService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendPasswordResetOtp implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public array $backoff = [60, 300, 900, 1800];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $actorId,
        public readonly int $deliveryVersion,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(PasswordResetService $passwordResets): void
    {
        $passwordResets->deliver($this->userId, $this->actorId, $this->deliveryVersion);
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->deliveryVersion;
    }

    public function failed(?Throwable $exception): void
    {
        app(PasswordResetService::class)->markDeliveryFailed(
            $this->userId,
            $this->deliveryVersion,
            $exception,
        );
    }
}
