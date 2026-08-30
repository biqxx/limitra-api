<?php

namespace App\AI\Data;

use App\Models\AI\Conversation;

readonly class ToolContext
{
    public function __construct(
        public ?int $userId,
        public int $conversationId,
        public string $platform,
    ) {}

    public static function fromConversation(Conversation $conversation): self
    {
        return new self(
            userId: $conversation->user_id === null ? null : (int) $conversation->user_id,
            conversationId: (int) $conversation->id,
            platform: $conversation->platform,
        );
    }
}
