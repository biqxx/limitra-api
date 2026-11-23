<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'attributes',
        'images',
        'price',
        'stock',
        'low_stock_threshold',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'images' => 'array',
            'price' => 'decimal:2',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hasOwnPrice(): bool
    {
        return $this->price !== null && (float) $this->price > 0;
    }

    public function effectivePrice(?Product $product = null): string
    {
        if ($this->hasOwnPrice()) {
            return number_format((float) $this->price, 2, '.', '');
        }

        $parent = $product ?? ($this->relationLoaded('product') ? $this->product : $this->product()->first());

        return $parent ? $parent->effectivePrice() : '0.00';
    }
}
