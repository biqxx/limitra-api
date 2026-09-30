<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSource extends Model
{
    protected $fillable = [
        'product_id',
        'supplier',
        'external_product_id',
        'supplier_url',
        'supplier_price',
        'supplier_currency',
        'available',
        'weight_lb',
        'delivery_days_min',
        'delivery_days_max',
        'restriction_status',
        'validation_status',
        'final_price_ngn',
        'variants',
        'cost_breakdown',
        'source_snapshot',
        'sync_status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'supplier_price' => 'decimal:4',
            'weight_lb' => 'decimal:4',
            'final_price_ngn' => 'decimal:2',
            'variants' => 'array',
            'cost_breakdown' => 'array',
            'source_snapshot' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
