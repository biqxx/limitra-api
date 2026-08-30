<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    protected $fillable = [
        'return_request_id', 'order_item_id', 'quantity', 'approved_quantity',
        'reason', 'notes', 'unit_price', 'requested_amount', 'approved_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'approved_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
