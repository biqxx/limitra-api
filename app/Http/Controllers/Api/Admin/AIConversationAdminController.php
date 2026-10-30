<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AIConversationAdminResource;
use App\Models\AI\Conversation;
use App\Services\Admin\AuditEventService;
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

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load(['user', 'socialAccount', 'messages' => fn ($q) => $q->oldest('id')]);

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
