<?php

namespace App\Models\Product;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourcingJob extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'supplier',
        'external_product_id',
        'status',
        'attempts',
        'error',
        'warning',
        'supplier_product',
        'enhanced_content',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'supplier_product' => 'array',
            'enhanced_content' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
