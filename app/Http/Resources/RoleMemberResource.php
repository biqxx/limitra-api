<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->profile?->full_name,
            'email' => $this->email,
            'legacy_role' => $this->role,
            'is_primary' => (bool) $this->pivot->is_primary,
            'assigned_by' => $this->pivot->assigned_by,
            'assigned_at' => $this->pivot->assigned_at,
        ];
    }
}
