<?php

namespace App\Models\Affiliate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateTierHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'affiliate_id',
        'from_tier',
        'to_tier',
        'qualifying_delivered_orders_count',
        'qualifying_sales_volume',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'qualifying_delivered_orders_count' => 'integer',
            'qualifying_sales_volume' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
