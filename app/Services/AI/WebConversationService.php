<?php

namespace App\Services\AI;

use App\AI\AgentService;
use App\Jobs\AI\ProcessAIMessageJob;
use App\Models\AI\Conversation;
use App\Models\AI\ConversationMessage;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebConversationService
{
    public function __construct(
        private readonly AgentService $agent,
        private readonly AttachmentSecurityScanner $attachmentScanner = new AttachmentSecurityScanner,
    ) {}

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
        $this->assertOwnerOrStaff($conversation, $user);

        return $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->whereNull('tool_calls')
            ->oldest('id')
            ->cursorPaginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images
     * @return array{message: ConversationMessage, replayed: bool, async?: bool}
     */
    public function send(Conversation $conversation, User $user, array $data, array $images = []): array
    {
        $this->assertOwner($conversation, $user);
        $lock = Cache::lock("ai:web:conversation:{$conversation->id}", 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'conversation' => ['Another message is currently being processed for this conversation.'],
            ]);
        }

        try {
            $requestMessage = $this->requestMessage($conversation, $user, $data, $images);
            $existing = ConversationMessage::query()
                ->where('request_message_id', $requestMessage->id)
                ->where('role', 'assistant')
                ->whereNull('tool_calls')
                ->first();
            if ($existing) {
                return ['message' => $existing, 'replayed' => true];
            }

            if (! empty($data['async'])) {
                ProcessAIMessageJob::dispatch($conversation->id, $requestMessage->id);

                return ['message' => $requestMessage, 'replayed' => false, 'async' => true];
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

    /** @return array{conversation: Conversation, ticket: SupportTicket} */
    public function handoff(
        Conversation $conversation,
        User $user,
        ?string $reason,
        SupportTicketService $tickets,
    ): array {
        $this->assertOwner($conversation, $user);

        return DB::transaction(function () use ($conversation, $user, $reason, $tickets): array {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($conversation->status === 'closed') {
                throw ValidationException::withMessages([
                    'conversation' => ['Cannot hand off a closed conversation.'],
                ]);
            }

            // Idempotent handoff: return existing ticket if already handed off
            if (! empty($conversation->context['support_ticket_id'])) {
                $existingTicket = SupportTicket::query()->find($conversation->context['support_ticket_id']);
                if ($existingTicket) {
                    return [
                        'conversation' => $conversation,
                        'ticket' => $existingTicket,
                    ];
                }
            }

            $subject = "AI Support Transfer: Conversation #{$conversation->id}";
            $messageContent = 'Customer requested human support handoff from AI conversation.'
                .($reason ? "\nReason: {$reason}" : '');

            $ticket = $tickets->create([
                'category' => 'general',
                'subject' => $subject,
                'message' => $messageContent,
                'order_id' => $conversation->context['order_id'] ?? null,
                'contact_name' => $user->name ?? $user->username,
                'contact_email' => $user->email,
            ], $user);

            $conversation->forceFill([
                'status' => 'handed_off',
                'context' => array_merge($conversation->context ?? [], [
                    'support_ticket_id' => $ticket->id,
                    'support_ticket_number' => $ticket->number,
                    'handoff_reason' => $reason,
                    'handed_off_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => "You have been transferred to human support. Your support ticket is #{$ticket->number}.",
                'metadata' => [
                    'actions' => [
                        [
                            'type' => 'ticket_created',
                            'ticket_id' => $ticket->id,
                            'ticket_number' => $ticket->number,
                        ],
                    ],
                ],
            ]);

            return [
                'conversation' => $conversation->fresh(),
                'ticket' => $ticket,
            ];
        }, 3);
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

    /**
     * @return array<string, mixed>
     */
    public function getAttachment(Conversation $conversation, User $user, string $attachmentId): array
    {
        $this->assertOwnerOrStaff($conversation, $user);

        $messages = $conversation->messages()
            ->whereNotNull('metadata')
            ->get();

        foreach ($messages as $msg) {
            $attachments = $msg->metadata['attachments'] ?? [];
            foreach ($attachments as $att) {
                if (($att['id'] ?? null) === $attachmentId) {
                    return $att;
                }
            }
        }

        abort(404, 'Attachment not found.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images
     */
    private function requestMessage(Conversation $conversation, User $user, array $data, array $images = []): ConversationMessage
    {
        return DB::transaction(function () use ($conversation, $user, $data, $images): ConversationMessage {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertOwner($conversation, $user);
            if (! in_array($conversation->status, ['open', 'handed_off'], true)) {
                throw ValidationException::withMessages([
                    'conversation' => ['This conversation is closed.'],
                ]);
            }

            // Check if client message ID was already used
            $existing = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_message_id', $data['client_message_id'])
                ->first();

            if ($existing) {
                if ($existing->role !== 'user' || ! hash_equals((string) $existing->content, (string) $data['text'])) {
                    throw ValidationException::withMessages([
                        'client_message_id' => ['This client message ID was already used for different content.'],
                    ]);
                }

                return $existing;
            }

            $storedAttachments = [];
            foreach ($images as $img) {
                if ($img instanceof UploadedFile) {
                    $attData = $this->attachmentScanner->validateAndStore($img, $conversation->id);
                    $attData['download_url'] = url("/api/v1/ai/conversations/{$conversation->id}/attachments/{$attData['id']}");
                    $storedAttachments[] = $attData;
                }
            }

            return ConversationMessage::query()->create([
                'conversation_id' => $conversation->id,
                'client_message_id' => $data['client_message_id'],
                'role' => 'user',
                'content' => $data['text'],
                'metadata' => ! empty($storedAttachments) ? ['attachments' => $storedAttachments] : null,
            ]);
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

    private function assertOwnerOrStaff(Conversation $conversation, User $user): void
    {
        if ($conversation->user_id === $user->id && $conversation->platform === 'web') {
            return;
        }

        if (in_array($user->role, ['admin', 'staff'], true)) {
            return;
        }

        abort(403, 'Forbidden.');
    }
}
