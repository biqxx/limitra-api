<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class DatabaseNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'event' => $data['event'] ?? Str::of($this->type)->afterLast('\\')->snake()->toString(),
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'severity' => $data['severity'] ?? 'info',
            'action' => $data['action'] ?? null,
            'metadata' => $data['metadata'] ?? (object) [],
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
