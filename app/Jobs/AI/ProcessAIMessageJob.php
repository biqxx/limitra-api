<?php

namespace App\Jobs\AI;

use App\AI\AgentService;
use App\Models\AI\Conversation;
use App\Models\AI\ConversationMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAIMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public int $timeout = 180;

    public function __construct(
        public int $conversationId,
        public int $requestMessageId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(AgentService $agent): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        $requestMessage = ConversationMessage::query()->find($this->requestMessageId);

        if (! $conversation || ! $requestMessage) {
            return;
        }

        $existing = ConversationMessage::query()
            ->where('request_message_id', $requestMessage->id)
            ->where('role', 'assistant')
            ->whereNull('tool_calls')
            ->first();

        if ($existing) {
            return;
        }

        $agent->respondToMessage($conversation, $requestMessage);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            report($exception);
        }
    }
}
