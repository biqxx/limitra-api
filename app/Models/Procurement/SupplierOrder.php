<?php

namespace App\Models\Procurement;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierOrder extends Model
{
    protected $fillable = [
        'order_id',
        'order_item_id',
        'supplier',
        'external_supplier_order_id',
        'status',
        'carrier',
        'tracking_number',
        'procurement_cost_amount',
        'procurement_cost_currency',
        'exchange_rate',
        'local_cost_amount',
        'shipping_cost_amount',
        'customs_cost_amount',
        'weight_kg',
        'volumetric_weight_kg',
        'purchased_at',
        'received_at_hub_at',
        'dispatched_at',
        'notes',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'procurement_cost_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'local_cost_amount' => 'decimal:2',
            'shipping_cost_amount' => 'decimal:2',
            'customs_cost_amount' => 'decimal:2',
            'weight_kg' => 'decimal:3',
            'volumetric_weight_kg' => 'decimal:3',
            'purchased_at' => 'datetime',
            'received_at_hub_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupplierOrderEvent::class)->latest('id');
    }
}
