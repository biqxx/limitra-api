<?php

namespace App\Social\Data;

readonly class InboundMessage
{
    public function __construct(
        public string $platform,            // whatsapp | instagram | facebook | x
        public string $providerMessageId,
        public string $platformSenderId,    // sender's platform-assigned ID
        public string $platformRecipientId, // page/number that received the message
        public string $message,
        public ?string $username,            // display name if available
        public int $timestamp,
    ) {}
}
