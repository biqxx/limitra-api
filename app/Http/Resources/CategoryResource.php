<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'age_gated' => $this->age_gated,
            'active' => $this->active,
            'image' => $this->whenLoaded('image', fn () => $this->image ? [
                'id' => $this->image->id,
                'url' => $this->image->path,
                'alt' => $this->image->alt,
            ] : null),
            'products_count' => $this->whenCounted('products'),
            'subcategories' => CategoryResource::collection($this->whenLoaded('subcategories')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
