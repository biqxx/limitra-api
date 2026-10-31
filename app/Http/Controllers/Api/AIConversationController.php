<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AI\IndexAIConversationMessagesRequest;
use App\Http\Requests\AI\StoreAIConversationMessageRequest;
use App\Http\Requests\AI\StoreAIConversationRequest;
use App\Http\Resources\AIConversationMessageResource;
use App\Http\Resources\AIConversationResource;
use App\Http\Resources\SupportTicketResource;
use App\Models\AI\Conversation;
use App\Services\AI\WebConversationService;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $uploadedImages = $request->file('images', $request->file('attachments', []));
        $result = $conversations->send(
            $conversation,
            $request->user('api'),
            $request->validated(),
            $uploadedImages,
        );

        if (! empty($result['async'])) {
            return $this->success(
                [
                    'status' => 'processing',
                    'client_message_id' => $result['message']->client_message_id ?? $request->input('client_message_id'),
                    'request_message' => new AIConversationMessageResource($result['message']),
                ],
                'AI response processing asynchronously.',
                202,
            );
        }

        return $this->success(
            new AIConversationMessageResource($result['message']),
            $result['replayed'] ? 'AI response replayed.' : 'AI response created.',
            $result['replayed'] ? 200 : 201,
        );
    }

    public function handoff(
        Request $request,
        Conversation $conversation,
        WebConversationService $conversations,
        SupportTicketService $tickets,
    ): JsonResponse {
        $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $result = $conversations->handoff(
            $conversation,
            $request->user('api'),
            $request->input('reason'),
            $tickets,
        );

        return $this->success([
            'conversation' => new AIConversationResource($result['conversation']),
            'ticket' => new SupportTicketResource($result['ticket']),
        ], 'Conversation transferred to human support successfully.', 200);
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

    public function attachment(
        Request $request,
        Conversation $conversation,
        string $attachment,
        WebConversationService $conversations,
    ): StreamedResponse {
        $data = $conversations->getAttachment($conversation, $request->user('api'), $attachment);

        return Storage::disk('local')->download(
            $data['stored_path'],
            $data['file_name'],
            ['Content-Type' => $data['mime'] ?? 'application/octet-stream'],
        );
    }
}
