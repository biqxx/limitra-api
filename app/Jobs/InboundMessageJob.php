<?php

namespace App\Jobs;

use App\AI\AgentService;
use App\Models\AI\Conversation;
use App\Models\Social\InboundSocialMessage;
use App\Models\Social\SocialAccount;
use App\Social\SocialChannelManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InboundMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 30, 60];

    public readonly string $processingToken;

    public function __construct(public readonly int $messageId)
    {
        $this->processingToken = (string) Str::uuid();
    }

    public function handle(SocialChannelManager $channels, AgentService $agent): void
    {
        $message = InboundSocialMessage::find($this->messageId);

        if (! $message || $message->status === 'processed') {
            return;
        }

        if (! $this->claim()) {
            if ($message->fresh()?->status === 'processing' && $this->job !== null) {
                $this->release(30);
            }

            return;
        }

        try {
            $socialAccount = $this->resolveOrCreateContact($message);
            $conversation = $this->findOrCreateConversation($socialAccount);

            $message->update(['conversation_id' => $conversation->id]);
            $reply = $message->reply;

            if ($reply === null) {
                $reply = $agent->respond($conversation, $message->message);
                $message->update(['reply' => $reply]);
            }

            $channels->sendTextMessage(
                $message->platform,
                $message->platform_sender_id,
                $reply,
            );

            InboundSocialMessage::whereKey($message->id)
                ->where('processing_token', $this->processingToken)
                ->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'processing_token' => null,
                    'processing_started_at' => null,
                    'last_error' => null,
                ]);
        } catch (Throwable $exception) {
            $this->markFailedAttempt($exception);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailedAttempt($exception);

        Log::error('InboundMessageJob failed', [
            'inbound_message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function claim(): bool
    {
        return InboundSocialMessage::whereKey($this->messageId)
            ->where(function ($query): void {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function ($stale): void {
                        $stale->where('status', 'processing')
                            ->where('processing_started_at', '<=', now()->subMinutes(5));
                    });
            })
            ->update([
                'status' => 'processing',
                'processing_token' => $this->processingToken,
                'processing_started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
            ]) === 1;
    }

    private function markFailedAttempt(Throwable $exception): void
    {
        InboundSocialMessage::whereKey($this->messageId)
            ->where('processing_token', $this->processingToken)
            ->update([
                'status' => 'failed',
                'processing_token' => null,
                'processing_started_at' => null,
                'last_error' => Str::limit($exception->getMessage(), 2000),
            ]);
    }

    private function resolveOrCreateContact(InboundSocialMessage $message): SocialAccount
    {
        return SocialAccount::firstOrCreate(
            [
                'platform' => $message->platform,
                'platform_sender_id' => $message->platform_sender_id,
            ],
            [
                'user_id' => null,
                'username' => $message->username,
            ],
        );
    }

    private function findOrCreateConversation(SocialAccount $account): Conversation
    {
        return Conversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'platform' => $account->platform,
                'status' => 'open',
            ],
            [
                'user_id' => $account->user_id,
            ],
        );
    }
}
