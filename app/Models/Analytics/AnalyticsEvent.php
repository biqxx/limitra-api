<?php

namespace App\Models\Analytics;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'user_id',
        'session_id',
        'event_type',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pageView(): HasOne
    {
        return $this->hasOne(PageView::class);
    }

    public function productView(): HasOne
    {
        return $this->hasOne(ProductView::class);
    }

    public function cartEvent(): HasOne
    {
        return $this->hasOne(CartEvent::class);
    }

    public function orderEvent(): HasOne
    {
        return $this->hasOne(OrderEvent::class);
    }
}
