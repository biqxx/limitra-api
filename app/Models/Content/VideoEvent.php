<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'video_id',
        'user_id',
        'event_type',
        'duration_watched',
        'session_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_watched' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
