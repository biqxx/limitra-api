<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends BaseController
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success([
            'events' => $this->preferences->matrix($this->user($request)),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'max:100'],
            'preferences.*.event' => ['required', 'string', 'max:120', 'distinct'],
            'preferences.*.channels' => ['required', 'array', 'min:1'],
            'preferences.*.channels.*' => ['required', 'boolean'],
        ]);

        return $this->success([
            'events' => $this->preferences->replace($this->user($request), $data['preferences']),
        ], 'Notification preferences updated.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('api');

        return $user;
    }
}
