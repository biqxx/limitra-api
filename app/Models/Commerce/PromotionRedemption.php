<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;

class PromotionRedemption extends Model
{
    protected $fillable = ['promotion_id', 'user_id', 'order_id', 'discount_amount'];

    protected function casts(): array
    {
        return ['discount_amount' => 'decimal:2'];
    }
}
