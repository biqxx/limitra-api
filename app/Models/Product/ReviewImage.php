<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewImage extends Model
{
    protected $fillable = ['review_id', 'path', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
