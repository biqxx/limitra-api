<?php

namespace App\Models\Content;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ContentEntry extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'channel',
        'locale',
        'body',
        'seo_title',
        'seo_description',
        'status',
        'scheduled_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function revisions(): MorphMany
    {
        return $this->morphMany(ContentRevision::class, 'revisionable')->latest('id');
    }
}
