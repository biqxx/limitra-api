<?php

namespace App\Models\Payment;

use App\Models\Order\Order;
use App\Models\Order\ReturnRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = [
        'return_request_id', 'order_id', 'payment_id', 'user_id', 'processed_by',
        'reference', 'provider', 'method', 'status', 'currency', 'amount', 'amount_minor',
        'source', 'automation_key',
        'provider_refund_id', 'provider_reference', 'reason', 'failure_message',
        'provider_metadata', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_minor' => 'integer',
            'provider_metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
