<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BlogPost extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'author_id',
        'excerpt',
        'content',
        'cover_image_url',
        'tags',
        'category',
        'status',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(ContentRevision::class, 'revisionable')->latest('id');
    }
}
