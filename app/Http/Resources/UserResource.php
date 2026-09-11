<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\User\Role;
use App\Services\Auth\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $permissionResolver = app(PermissionResolver::class);
        $roles = $permissionResolver->roles($user);

        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->profile?->full_name,
            'email' => $this->email,
            'phone' => $this->profile?->phone,
            'avatar_url' => $this->profile?->avatar ? Storage::disk('public')->url($this->profile->avatar) : null,
            'role' => $this->role,
            'permissions' => $permissionResolver->resolve($user),
            'linked_roles' => [
                'affiliate' => $roles->contains('name', 'affiliate'),
                'staff' => $roles->contains(fn (Role $role): bool => in_array($role->name, ['staff', 'admin'], true)),
            ],
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'is_primary' => (bool) $role->pivot->is_primary,
            ])->values(),
            'email_verified_at' => $this->email_verified_at,
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
