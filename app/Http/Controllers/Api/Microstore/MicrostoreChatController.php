<?php

namespace App\Http\Controllers\Api\Microstore;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreConversation;
use App\Models\Microstore\MicrostoreMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class MicrostoreChatController extends BaseController
{
    /**
     * Customer: get or initialize chat thread with a store owner.
     */
    #[OA\Get(
        path: '/microstores/{slug}/chat',
        tags: ['Microstore Chat'],
        summary: 'Get or start customer chat with a microstore owner',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Conversation retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function customerShow(Request $request, string $slug): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->publicStoreOrFail($request, $slug);

        $conversation = MicrostoreConversation::firstOrCreate(
            ['microstore_id' => $microstore->id, 'customer_id' => $user->id],
            ['status' => 'open']
        );

        MicrostoreMessage::where('conversation_id', $conversation->id)
            ->where('sender_role', 'owner')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($conversation->customer_unread_count > 0) {
            $conversation->update(['customer_unread_count' => 0]);
        }

        $conversation->load(['messages.sender:id,name,username']);

        return $this->success([
            'conversation' => $conversation,
            'store' => [
                'id' => $microstore->id,
                'name' => $microstore->name,
                'slug' => $microstore->slug,
                'type' => $microstore->type,
                'logo_url' => $microstore->logo_url,
            ],
        ], 'Store conversation retrieved.');
    }

    /**
     * Customer: send a message to the store owner.
     */
    #[OA\Post(
        path: '/microstores/{slug}/chat/messages',
        tags: ['Microstore Chat'],
        summary: 'Send message to microstore owner',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 201, description: 'Message sent', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function customerSend(Request $request, string $slug): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->publicStoreOrFail($request, $slug);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = DB::transaction(function () use ($microstore, $user, $validated): MicrostoreMessage {
            $conversation = MicrostoreConversation::firstOrCreate(
                ['microstore_id' => $microstore->id, 'customer_id' => $user->id],
                ['status' => 'open']
            );

            $msg = MicrostoreMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'sender_role' => 'customer',
                'body' => trim($validated['body']),
            ]);

            $conversation->increment('owner_unread_count');
            $conversation->update(['last_message_at' => now()]);

            return $msg->load('sender:id,name,username');
        });

        return $this->success($message, 'Message sent to store owner.', 201);
    }

    /**
     * Store owner: list all customer conversations for their store.
     */
    #[OA\Get(
        path: '/my-microstore/chats',
        tags: ['My Microstore'],
        summary: 'List customer chat conversations for microstore owner',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Conversations retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerIndex(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $paginator = MicrostoreConversation::query()
            ->with(['customer:id,name,username,email', 'latestMessage'])
            ->where('microstore_id', $microstore->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Store conversations retrieved.');
    }

    /**
     * Store owner: read a customer conversation and mark customer messages as read.
     */
    #[OA\Get(
        path: '/my-microstore/chats/{conversation}',
        tags: ['My Microstore'],
        summary: 'Read a microstore customer conversation',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Conversation retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerShow(Request $request, int $conversationId): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);

        $conversation = MicrostoreConversation::query()
            ->with(['customer:id,name,username,email', 'messages.sender:id,name,username'])
            ->where('microstore_id', $microstore->id)
            ->findOrFail($conversationId);

        MicrostoreMessage::where('conversation_id', $conversation->id)
            ->where('sender_role', 'customer')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($conversation->owner_unread_count > 0) {
            $conversation->update(['owner_unread_count' => 0]);
        }

        return $this->success($conversation, 'Conversation retrieved.');
    }

    /**
     * Store owner: reply to a customer conversation.
     */
    #[OA\Post(
        path: '/my-microstore/chats/{conversation}/messages',
        tags: ['My Microstore'],
        summary: 'Reply to a microstore customer conversation',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 201, description: 'Reply sent', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function ownerReply(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->ownedStoreOrFail($request);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $conversation = MicrostoreConversation::query()
            ->where('microstore_id', $microstore->id)
            ->findOrFail($conversationId);

        $message = DB::transaction(function () use ($conversation, $user, $validated): MicrostoreMessage {
            $msg = MicrostoreMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'sender_role' => 'owner',
                'body' => trim($validated['body']),
            ]);

            $conversation->increment('customer_unread_count');
            $conversation->update(['last_message_at' => now()]);

            return $msg->load('sender:id,name,username');
        });

        return $this->success($message, 'Reply sent.', 201);
    }

    private function publicStoreOrFail(Request $request, string $slug): Microstore
    {
        $type = $request->route('storeType') ?? 'microstore';
        $storeType = in_array($type, ['microstore', 'celebrity'], true) ? $type : 'microstore';

        return Microstore::query()
            ->where('slug', $slug)
            ->where('type', $storeType)
            ->where('status', 'active')
            ->where('moderation_status', 'approved')
            ->firstOrFail();
    }

    private function ownedStoreOrFail(Request $request): Microstore
    {
        $user = $request->user('api');
        $type = $request->route('storeType') ?? 'microstore';
        $storeType = in_array($type, ['microstore', 'celebrity'], true) ? $type : 'microstore';

        return Microstore::where('user_id', $user->id)
            ->where('type', $storeType)
            ->firstOrFail();
    }
}

