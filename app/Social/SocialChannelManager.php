<?php

namespace App\Social;

use App\Social\Contracts\SocialChannel;
use Illuminate\Support\Facades\Log;

class SocialChannelManager
{
    /** @param SocialChannel[] $channels */
    public function __construct(private readonly array $channels) {}

    public function sendTextMessage(string $platform, string $recipientId, string $message): void
    {
        foreach ($this->channels as $channel) {
            if ($channel->handles($platform)) {
                $channel->sendTextMessage($platform, $recipientId, $message);

                return;
            }
        }

        Log::warning("SocialChannelManager: no channel registered for platform '{$platform}'");
    }
}
