<?php

namespace App\Social;

use App\Jobs\InboundMessageJob;
use App\Models\Social\InboundSocialMessage;
use App\Social\Data\InboundMessage;
use Carbon\CarbonImmutable;

class InboundMessageReceiver
{
    public function accept(InboundMessage $message): bool
    {
        $record = InboundSocialMessage::firstOrCreate(
            [
                'platform' => $message->platform,
                'provider_message_id' => $message->providerMessageId,
            ],
            [
                'platform_sender_id' => $message->platformSenderId,
                'platform_recipient_id' => $message->platformRecipientId,
                'username' => $message->username,
                'message' => $message->message,
                'occurred_at' => CarbonImmutable::createFromTimestampUTC($message->timestamp),
                'status' => 'pending',
            ],
        );

        if (! $record->wasRecentlyCreated) {
            return false;
        }

        InboundMessageJob::dispatch($record->id)->afterCommit();

        return true;
    }
}
