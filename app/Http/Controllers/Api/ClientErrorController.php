<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class ClientErrorController extends BaseController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['required', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:1000'],
            'digest' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user('api');
        $page = trim($data['page']);
        $msg = trim((string) ($data['message'] ?? ''));
        $actorLabel = $user ? ($user->email ?: ($user->name ?: "User #{$user->id}")) : 'Guest visitor';

        $payload = [
            'event' => 'system.client_error_500',
            'title' => "Error 500 on {$page}",
            'message' => "{$actorLabel} encountered an error on {$page}".($msg !== '' ? ": {$msg}" : ''),
            'severity' => 'error',
            'action' => [
                'label' => 'Open page',
                'url' => $page,
            ],
            'metadata' => [
                'page' => $page,
                'url' => $data['url'] ?? null,
                'error_message' => $msg !== '' ? $msg : null,
                'digest' => $data['digest'] ?? null,
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'ip' => $request->ip(),
            ],
        ];

        $admins = User::query()->where('role', 'admin')->get();
        foreach ($admins as $admin) {
            DatabaseNotification::query()->create([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\ClientError500Notification',
                'notifiable_type' => User::class,
                'notifiable_id' => $admin->id,
                'data' => $payload,
            ]);
        }

        return $this->success(['notified_admins' => $admins->count()], 'Client error reported.');
    }
}

