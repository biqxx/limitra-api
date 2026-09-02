<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingController extends BaseController
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function index(): JsonResponse
    {
        return $this->success([
            'events' => $this->preferences->adminSettings(),
        ]);
    }

    public function update(Request $request, string $event): JsonResponse
    {
        $data = $request->validate([
            'default_channels' => ['required', 'array', 'min:1'],
            'default_channels.*' => ['required', 'string', 'distinct'],
        ]);

        return $this->success(
            $this->preferences->updateDefaults($event, $data['default_channels']),
            'Notification defaults updated.',
        );
    }
}
