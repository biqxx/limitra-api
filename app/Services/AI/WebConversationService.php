<?php

namespace App\Services\AI;

use App\AI\AgentService;
use App\Models\AI\Conversation;
use App\Models\AI\ConversationMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebConversationService
{
    public function __construct(private readonly AgentService $agent) {}

    /** @return array{conversation: Conversation, greeting: ConversationMessage} */
    public function create(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $conversation = Conversation::query()->create([
                'user_id' => $user->id,
                'social_account_id' => null,
                'platform' => 'web',
                'status' => 'open',
                'context' => $data['context'] ?? null,
                'last_message_at' => now(),
            ]);
            $greeting = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => config('ai.web_greeting'),
            ]);

            return ['conversation' => $conversation, 'greeting' => $greeting];
        }, 3);
    }

    public function messages(Conversation $conversation, User $user, int $perPage): CursorPaginator
    {
        $this->assertOwner($conversation, $user);

        return $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->whereNull('tool_calls')
            ->oldest('id')
            ->cursorPaginate($perPage);
    }

    /** @return array{message: ConversationMessage, replayed: bool} */
    public function send(Conversation $conversation, User $user, array $data): array
    {
        $this->assertOwner($conversation, $user);
        $lock = Cache::lock("ai:web:conversation:{$conversation->id}", 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'conversation' => ['Another message is currently being processed for this conversation.'],
            ]);
        }

        try {
            $requestMessage = $this->requestMessage($conversation, $user, $data);
            $existing = ConversationMessage::query()
                ->where('request_message_id', $requestMessage->id)
                ->where('role', 'assistant')
                ->whereNull('tool_calls')
                ->first();
            if ($existing) {
                return ['message' => $existing, 'replayed' => true];
            }

            $assistant = $this->agent->respondToMessage($conversation->fresh(), $requestMessage);
            $output = $this->toolOutput($requestMessage, $assistant);
            $assistant->forceFill([
                'metadata' => array_merge($assistant->metadata ?? [], $output),
            ])->save();

            return ['message' => $assistant->fresh(), 'replayed' => false];
        } finally {
            $lock->release();
        }
    }

    public function close(Conversation $conversation, User $user): Conversation
    {
        $this->assertOwner($conversation, $user);

        return DB::transaction(function () use ($conversation): Conversation {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($conversation->status !== 'closed') {
                $conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            }

            return $conversation;
        }, 3);
    }

    private function requestMessage(Conversation $conversation, User $user, array $data): ConversationMessage
    {
        return DB::transaction(function () use ($conversation, $user, $data): ConversationMessage {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertOwner($conversation, $user);
            if ($conversation->status !== 'open') {
                throw ValidationException::withMessages([
                    'conversation' => ['This conversation is closed.'],
                ]);
            }

            $message = ConversationMessage::query()->firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'client_message_id' => $data['client_message_id'],
                ],
                ['role' => 'user', 'content' => $data['text']],
            );
            if ($message->role !== 'user' || ! hash_equals((string) $message->content, $data['text'])) {
                throw ValidationException::withMessages([
                    'client_message_id' => ['This client message ID was already used for different content.'],
                ]);
            }

            return $message;
        }, 3);
    }

    /** @return array{products: array<int, mixed>, actions: array<int, array<string, mixed>>} */
    private function toolOutput(ConversationMessage $request, ConversationMessage $assistant): array
    {
        $products = collect();
        $actions = collect();
        $toolMessages = ConversationMessage::query()
            ->where('conversation_id', $request->conversation_id)
            ->where('role', 'tool')
            ->whereBetween('id', [$request->id, $assistant->id])
            ->get();

        foreach ($toolMessages->flatMap(fn (ConversationMessage $message): array => $message->tool_results ?? []) as $entry) {
            $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];
            $name = $entry['name'] ?? null;
            if (is_array($result['products'] ?? null)) {
                $products->push(...$result['products']);
            } elseif ($name === 'get_product_details' && isset($result['id'])) {
                $products->push($result);
            }

            $action = match ($name) {
                'add_to_cart', 'remove_from_cart' => isset($result['cart_id'])
                    ? ['type' => 'cart_updated', 'cart_id' => $result['cart_id']]
                    : null,
                'generate_payment_link' => isset($result['checkout_url'])
                    ? ['type' => 'open_checkout', 'url' => $result['checkout_url']]
                    : null,
                'check_order_status' => isset($result['id'])
                    ? ['type' => 'open_order_tracking', 'order_id' => $result['id']]
                    : null,
                default => null,
            };
            if ($action) {
                $actions->push($action);
            }
        }

        return [
            'products' => $products->filter(fn (mixed $product): bool => is_array($product) && isset($product['id']))
                ->unique('id')->values()->all(),
            'actions' => $actions->values()->all(),
        ];
    }

    private function assertOwner(Conversation $conversation, User $user): void
    {
        if ($conversation->user_id !== $user->id || $conversation->platform !== 'web') {
            abort(403, 'Forbidden.');
        }
    }
}
