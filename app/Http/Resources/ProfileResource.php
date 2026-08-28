<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'avatar_url' => $this->avatar ? Storage::disk('public')->url($this->avatar) : null,
            'phone' => $this->phone,
            'date_of_birth' => $this->birthday?->toDateString(),
            'gender' => $this->gender,
            'subscribe_to_newsletter' => $this->subscribe_to_newsletter,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
