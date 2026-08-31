<?php

namespace App\Models\Analytics;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'analytics_event_id',
        'user_id',
        'session_id',
        'url',
        'referrer',
        'source',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function analyticsEvent(): BelongsTo
    {
        return $this->belongsTo(AnalyticsEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
