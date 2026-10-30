<?php

namespace App\Models\Product;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryAdjustment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'inventory_adjustments';

    protected $fillable = [
        'product_id',
        'variant_id',
        'adjusted_by',
        'quantity_delta',
        'quantity_before',
        'quantity_after',
        'reason',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Inventory adjustments are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Inventory adjustments cannot be deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
