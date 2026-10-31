<?php

namespace App\Models\Microstore;

use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrostoreEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'microstore_id',
        'user_id',
        'event_type',
        'product_id',
        'order_id',
        'session_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function microstore(): BelongsTo
    {
        return $this->belongsTo(Microstore::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
