<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = auth('api')->user();
        $canSeeModeration = $viewer && ($viewer->id === $this->user_id || $viewer->isStaff());

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'order_item_id' => $this->when($canSeeModeration, $this->order_item_id),
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'verified_purchase' => $this->verified_purchase,
            'helpful_count' => $this->helpful_count,
            'author' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'name' => $this->user->profile?->full_name,
                'avatar_url' => $this->user->profile?->avatar
                    ? Storage::disk('public')->url($this->user->profile->avatar)
                    : null,
            ]),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => Storage::disk('public')->url($image->path),
                'position' => $image->sort_order,
            ])->values()),
            'moderation_reason' => $this->when($canSeeModeration, $this->moderation_reason),
            'moderated_at' => $this->when($canSeeModeration, $this->moderated_at),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
