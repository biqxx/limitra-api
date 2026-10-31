<?php

namespace App\Models\Microstore;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrostoreProduct extends Model
{
    protected $fillable = [
        'microstore_id',
        'product_id',
        'custom_title',
        'custom_description',
        'sort_order',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function microstore(): BelongsTo
    {
        return $this->belongsTo(Microstore::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
