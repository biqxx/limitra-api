<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'brand' => $this->brand,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => $this->price,
            'compare_at_price' => $this->compare_at_price,
            'discount_percentage' => $this->compare_at_price && $this->compare_at_price > $this->price
                ? (int) round((1 - ((float) $this->price / (float) $this->compare_at_price)) * 100)
                : 0,
            'currency' => $this->currency,
            'stock' => $this->stock,
            'low_stock_threshold' => $this->low_stock_threshold,
            'in_stock' => $this->stock > 0,
            'status' => $this->status,
            'is_featured' => $this->is_featured,
            'is_bestseller' => $this->is_bestseller,
            'average_rating' => $this->average_rating,
            'review_count' => $this->review_count,
            'images' => $this->relationLoaded('productImages')
                ? $this->productImages->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->path,
                    'alt' => $image->alt,
                    'position' => $image->sort_order,
                ])->values()
                : ($this->images ?? []),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'attributes' => $variant->attributes,
                'price' => $variant->price ?? $this->price,
                'stock' => $variant->stock,
                'status' => $variant->status,
            ])),
            'specifications' => $this->whenLoaded('specifications', fn () => $this->specifications->map(fn ($specification) => [
                'id' => $specification->id,
                'group' => $specification->group,
                'name' => $specification->name,
                'value' => $specification->value,
                'position' => $specification->sort_order,
            ])),
            'seo_meta' => $this->seo_meta,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'subcategory' => new CategoryResource($this->whenLoaded('subcategory')),

            // Audit fields — included in admin/detail contexts when the
            // creator relationship is explicitly loaded.
            'created_by' => $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'updated_by' => $this->updated_by ?? [],  // array of user IDs

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
