<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaFile extends Model
{
    protected $fillable = [
        'uploader_id',
        'disk',
        'filename',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'title',
        'alt',
        'folder',
        'tags',
        'metadata',
    ];

    protected $appends = [
        'url',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
