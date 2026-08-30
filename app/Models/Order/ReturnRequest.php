<?php

namespace App\Models\Order;

use App\Models\Payment\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends Model
{
    protected $fillable = [
        'number', 'order_id', 'user_id', 'status', 'resolution', 'currency',
        'requested_total', 'approved_total', 'notes', 'admin_notes', 'rejection_reason',
        'approved_by', 'approved_at', 'cancelled_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_total' => 'decimal:2',
            'approved_total' => 'decimal:2',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReturnImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReturnEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
