<?php

namespace App\Jobs;

use App\Models\Referral\CustomerReferralShareEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordReferralShareEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 30];

    public function __construct(
        public readonly string $eventId,
        public readonly int $userId,
        public readonly int $referralCodeId,
        public readonly string $channel,
        public readonly string $sharedUrlHash,
        public readonly ?string $sharedPath,
        public readonly ?string $ipHash,
        public readonly ?string $userAgentHash,
        public readonly string $occurredAt,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        CustomerReferralShareEvent::query()->firstOrCreate(
            ['event_id' => $this->eventId],
            [
                'user_id' => $this->userId,
                'customer_referral_code_id' => $this->referralCodeId,
                'channel' => $this->channel,
                'shared_url_hash' => $this->sharedUrlHash,
                'shared_path' => $this->sharedPath,
                'ip_hash' => $this->ipHash,
                'user_agent_hash' => $this->userAgentHash,
                'occurred_at' => CarbonImmutable::parse($this->occurredAt),
            ],
        );
    }
}
