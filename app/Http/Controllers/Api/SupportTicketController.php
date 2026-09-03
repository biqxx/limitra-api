<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Support\IndexSupportTicketsRequest;
use App\Http\Requests\Support\StoreSupportTicketRequest;
use App\Http\Resources\SupportTicketResource;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends BaseController
{
    public function store(StoreSupportTicketRequest $request, SupportTicketService $tickets): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('api');

        return $this->success(
            new SupportTicketResource($tickets->create($request->validated(), $user)),
            'Support ticket created.',
            201,
        );
    }

    public function index(IndexSupportTicketsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = SupportTicket::query()
            ->where('user_id', $request->user('api')->id)
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with(['order', 'assignee'])
            ->withCount('messages')
            ->latest('last_message_at')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => SupportTicketResource::collection($page->getCollection()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $user = $request->user('api');
        if ($supportTicket->user_id !== $user->id && ! $user->isStaff()) {
            abort(403, 'Forbidden.');
        }

        return $this->success(new SupportTicketResource(
            $supportTicket->load(['order', 'assignee', 'messages.sender'])->loadCount('messages'),
        ));
    }
}
