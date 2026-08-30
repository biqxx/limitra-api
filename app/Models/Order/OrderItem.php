<?php

namespace App\Models\Order;

use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\Product\Review;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'product_name',
        'sku',
        'selected_options',
        'quantity',
        'unit_price',
        'price_at_purchase',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'selected_options' => 'array',
            'unit_price' => 'decimal:2',
            'price_at_purchase' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}
