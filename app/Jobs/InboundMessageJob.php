<?php

namespace App\Jobs;

use App\AI\AgentService;
use App\Models\AI\Conversation;
use App\Models\Social\SocialAccount;
use App\Models\User;
use App\Social\Data\InboundMessage;
use App\Social\SocialChannelManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboundMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 30, 60];

    public function __construct(public readonly InboundMessage $message) {}

    public function handle(SocialChannelManager $channels, AgentService $agent): void
    {
        $socialAccount = $this->resolveOrCreateContact($this->message);
        $conversation = $this->findOrCreateConversation($socialAccount);

        $reply = $agent->respond($conversation, $this->message->message);

        $channels->sendTextMessage(
            $this->message->platform,
            $this->message->platformSenderId,
            $reply,
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('InboundMessageJob failed', [
            'platform' => $this->message->platform,
            'platform_sender' => $this->message->platformSenderId,
            'error' => $e->getMessage(),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveOrCreateContact(InboundMessage $message): SocialAccount
    {
        $account = SocialAccount::where('platform', $message->platform)
            ->where('platform_sender_id', $message->platformSenderId)
            ->first();

        if ($account) {
            return $account;
        }

        // Create a prospect user so all contacts have a stable users.id.
        $user = User::create([
            'username' => $message->username ?? $message->platformSenderId,
            'email' => null,
            'password' => null,
            'role' => 'prospect',
        ]);

        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => $message->platform,
            'platform_sender_id' => $message->platformSenderId,
            'username' => $message->username,
        ]);
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
            ]
        );
    }
}
