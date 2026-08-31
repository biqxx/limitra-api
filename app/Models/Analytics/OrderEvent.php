<?php

namespace App\Models\Analytics;

use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'analytics_event_id',
        'user_id',
        'session_id',
        'order_id',
        'event_type',
        'amount',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
