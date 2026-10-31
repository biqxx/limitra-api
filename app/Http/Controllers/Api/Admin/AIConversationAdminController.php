<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AIConversationAdminResource;
use App\Models\AI\Conversation;
use App\Models\AI\ConversationMessage;
use App\Services\Admin\AuditEventService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AIConversationAdminController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Conversation::query()
            ->with(['user', 'socialAccount'])
            ->withCount('messages');

        if ($request->filled('channel')) {
            $query->where('platform', $request->string('channel')->toString());
        } elseif ($request->filled('platform')) {
            $query->where('platform', $request->string('platform')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(function ($sub) use ($search): void {
                $sub->whereHas('user', function ($u) use ($search): void {
                    $u->where('email', 'like', $search)
                        ->orWhere('username', 'like', $search);
                })->orWhere('platform_conversation_id', 'like', $search);
            });
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date('date_to'));
        }

        $conversations = $query->latest('last_message_at')
            ->paginate($request->integer('per_page', 15));

        return $this->success([
            'items' => AIConversationAdminResource::collection($conversations->items()),
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
                'last_page' => $conversations->lastPage(),
                'from' => $conversations->firstItem(),
                'to' => $conversations->lastItem(),
            ],
        ]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $totalConversations = Conversation::query()->count();
        $totalMessages = ConversationMessage::query()->count();
        $avgLatency = ConversationMessage::query()->whereNotNull('latency_ms')->avg('latency_ms');

        $messagesWithTokens = ConversationMessage::query()->whereNotNull('tokens')->get(['tokens']);
        $totalTokens = 0;
        foreach ($messagesWithTokens as $msg) {
            $tokens = $msg->tokens;
            if (is_array($tokens)) {
                $totalTokens += (int) ($tokens['total'] ?? $tokens['total_tokens'] ?? 0);
            } elseif (is_numeric($tokens)) {
                $totalTokens += (int) $tokens;
            }
        }

        $handedOffCount = Conversation::query()->where('status', 'handed_off')->count();
        $openCount = Conversation::query()->where('status', 'open')->count();
        $closedCount = Conversation::query()->where('status', 'closed')->count();
        $archivedCount = Conversation::query()->where('status', 'archived')->count();

        $platformBreakdown = Conversation::query()
            ->select('platform', DB::raw('count(*) as aggregate'))
            ->groupBy('platform')
            ->pluck('aggregate', 'platform')
            ->all();

        return $this->success([
            'total_conversations' => $totalConversations,
            'total_messages' => $totalMessages,
            'average_latency_ms' => $avgLatency !== null ? round((float) $avgLatency, 2) : 0,
            'total_tokens' => $totalTokens,
            'handoff_rate' => $totalConversations > 0 ? round(($handedOffCount / $totalConversations) * 100, 2) : 0.0,
            'status_breakdown' => [
                'open' => $openCount,
                'closed' => $closedCount,
                'handed_off' => $handedOffCount,
                'archived' => $archivedCount,
            ],
            'platform_breakdown' => $platformBreakdown,
        ], 'AI conversation metrics retrieved successfully.');
    }

    public function settings(BusinessSettingsService $settings): JsonResponse
    {
        return $this->success([
            'driver' => config('ai.driver', 'openrouter'),
            'primary_model' => $settings->value('ai.primary_model', 'inclusionai/ling-3.0-flash'),
            'fallback_model' => $settings->value('ai.fallback_model', 'upstage/solar-pro4'),
            'high_reasoning_model' => $settings->value('ai.high_reasoning_model', 'deepseek/deepseek-v4-flash-0731'),
            'max_tokens' => (int) config('ai.openrouter.max_tokens', 1024),
            'context_window' => (int) config('ai.context_window', 10),
            'max_tool_iterations' => (int) config('ai.max_tool_iterations', 5),
            'web_greeting' => (string) config('ai.web_greeting', 'Hi! How can I help you today?'),
            'enabled_tools' => [
                'get_product_recommendations',
                'get_product_details',
                'get_alternative_products',
                'check_order_status',
                'get_recent_orders',
                'add_to_cart',
                'generate_payment_link',
            ],
        ], 'AI settings retrieved successfully.');
    }

    public function updateSettings(
        Request $request,
        BusinessSettingsService $settingsService,
        AuditEventService $audits,
    ): JsonResponse {
        $validated = $request->validate([
            'primary_model' => ['nullable', 'string', 'min:3', 'max:255'],
            'fallback_model' => ['nullable', 'string', 'min:3', 'max:255'],
            'high_reasoning_model' => ['nullable', 'string', 'min:3', 'max:255'],
        ]);

        $changes = [];
        foreach (['primary_model', 'fallback_model', 'high_reasoning_model'] as $field) {
            if ($request->has($field) && $validated[$field] !== null) {
                $changes[] = [
                    'key' => "ai.{$field}",
                    'value' => $validated[$field],
                ];
            }
        }

        if (! empty($changes)) {
            $user = $request->user('api');
            $settingsService->update($changes, $user);
            $audits->record(
                action: 'ai_settings.updated',
                actor: $user,
                subject: null,
                before: null,
                after: $validated,
                metadata: ['keys' => array_column($changes, 'key')],
                request: $request,
            );
        }

        return $this->settings($settingsService);
    }

    public function show(
        Request $request,
        Conversation $conversation,
        AuditEventService $audits,
    ): JsonResponse {
        $conversation->load(['user', 'socialAccount', 'messages' => fn ($q) => $q->oldest('id')]);

        $audits->record(
            action: 'ai_conversation.viewed',
            actor: $request->user('api'),
            subject: $conversation,
            before: null,
            after: null,
            metadata: ['platform' => $conversation->platform],
            request: $request,
        );

        return $this->success(
            new AIConversationAdminResource($conversation),
            'AI conversation retrieved successfully.'
        );
    }

    public function update(
        Request $request,
        Conversation $conversation,
        AuditEventService $audits,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'closed', 'handed_off', 'archived'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $newStatus = $validated['status'];
        $notes = $validated['notes'] ?? null;

        $updated = DB::transaction(function () use ($conversation, $newStatus, $notes, $request, $audits): Conversation {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $previousStatus = $locked->status;
            $context = $locked->context ?? [];

            if ($notes) {
                $adminNotes = $context['admin_notes'] ?? [];
                $adminNotes[] = [
                    'note' => $notes,
                    'admin_id' => $request->user('api')?->id,
                    'created_at' => now()->toIso8601String(),
                ];
                $context['admin_notes'] = $adminNotes;
            }

            $locked->status = $newStatus;
            $locked->context = $context;
            if ($newStatus === 'closed' && ! $locked->closed_at) {
                $locked->closed_at = now();
            } elseif ($newStatus === 'open') {
                $locked->closed_at = null;
            }
            $locked->save();

            $audits->record(
                action: 'ai_conversation.status_updated',
                actor: $request->user('api'),
                subject: $locked,
                before: ['status' => $previousStatus],
                after: ['status' => $newStatus],
                metadata: ['notes' => $notes],
                request: $request,
            );

            return $locked;
        }, 3);

        $updated->load(['user', 'socialAccount', 'messages']);

        return $this->success(
            new AIConversationAdminResource($updated),
            'AI conversation updated successfully.'
        );
    }
}
