<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DatabaseNotificationResource;
use App\Models\User;
use App\Services\Notification\NotificationUnreadCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

class NotificationController extends BaseController
{
    public function __construct(private readonly NotificationUnreadCount $counts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['read', 'unread'])],
            'type' => ['sometimes', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $this->user($request);
        $page = $user->notifications()
            ->when(
                ($data['status'] ?? null) === 'unread',
                fn ($query) => $query->whereNull('read_at'),
            )
            ->when(
                ($data['status'] ?? null) === 'read',
                fn ($query) => $query->whereNotNull('read_at'),
            )
            ->when(
                $data['type'] ?? null,
                fn ($query, string $type) => $query->where('data->event', $type),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => DatabaseNotificationResource::collection($page->getCollection()),
            'unread_count' => $this->counts->get($user),
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

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'count' => $this->counts->get($this->user($request)),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);
        /** @var DatabaseNotification $record */
        $record = $user->notifications()->findOrFail($notification);

        if ($record->unread()) {
            $record->markAsRead();
            $this->counts->forget($user);
        }

        return $this->success(
            new DatabaseNotificationResource($record->refresh()),
            'Notification marked as read.',
        );
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $user->unreadNotifications()->update(['read_at' => now()]);
        $this->counts->forget($user);

        return $this->success(['unread_count' => 0], 'All notifications marked as read.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('api');

        return $user;
    }
}
