<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AI\IndexAIConversationMessagesRequest;
use App\Http\Requests\AI\StoreAIConversationMessageRequest;
use App\Http\Requests\AI\StoreAIConversationRequest;
use App\Http\Resources\AIConversationMessageResource;
use App\Http\Resources\AIConversationResource;
use App\Models\AI\Conversation;
use App\Services\AI\WebConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIConversationController extends BaseController
{
    public function store(
        StoreAIConversationRequest $request,
        WebConversationService $conversations,
    ): JsonResponse {
        $result = $conversations->create($request->user('api'), $request->validated());

        return $this->success([
            'conversation' => new AIConversationResource($result['conversation']),
            'greeting' => new AIConversationMessageResource($result['greeting']),
        ], 'AI conversation created.', 201);
    }

    public function messages(
        IndexAIConversationMessagesRequest $request,
        Conversation $conversation,
        WebConversationService $conversations,
    ): JsonResponse {
        $page = $conversations->messages(
            $conversation,
            $request->user('api'),
            $request->integer('per_page', 20),
        );

        return $this->success([
            'items' => AIConversationMessageResource::collection($page->items()),
            'pagination' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }

    public function message(
        StoreAIConversationMessageRequest $request,
        Conversation $conversation,
        WebConversationService $conversations,
    ): JsonResponse {
        $result = $conversations->send($conversation, $request->user('api'), $request->validated());

        return $this->success(
            new AIConversationMessageResource($result['message']),
            $result['replayed'] ? 'AI response replayed.' : 'AI response created.',
            $result['replayed'] ? 200 : 201,
        );
    }

    public function destroy(
        Request $request,
        Conversation $conversation,
        WebConversationService $conversations,
    ): JsonResponse {
        return $this->success(
            new AIConversationResource($conversations->close($conversation, $request->user('api'))),
            'AI conversation closed.',
        );
    }
}
