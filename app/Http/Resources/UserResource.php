<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->profile?->full_name,
            'email' => $this->email,
            'phone' => $this->profile?->phone,
            'avatar_url' => $this->profile?->avatar ? Storage::disk('public')->url($this->profile->avatar) : null,
            'role' => $this->role,
            'permissions' => match ($this->role) {
                'admin' => ['*'],
                'staff' => ['customers.read', 'orders.read', 'orders.update', 'affiliates.read'],
                'affiliate' => ['affiliate.dashboard', 'affiliate.links', 'affiliate.payouts'],
                default => ['account.manage', 'orders.manage', 'cart.manage'],
            },
            'linked_roles' => [
                'affiliate' => $this->role === 'affiliate',
                'staff' => in_array($this->role, ['staff', 'admin'], true),
            ],
            'email_verified_at' => $this->email_verified_at,
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
