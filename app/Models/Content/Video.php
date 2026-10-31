<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'url',
        'thumbnail_url',
        'category',
        'duration_seconds',
        'views_count',
        'likes_count',
        'shares_count',
        'is_featured',
        'tagged_product_ids',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'tagged_product_ids' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(VideoEvent::class);
    }
}
