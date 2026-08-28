<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeliveryMethod extends Model
{
    protected $fillable = ['code', 'name', 'type', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryZone::class, 'delivery_zone_method')
            ->withPivot(['fee', 'free_shipping_threshold', 'minimum_order', 'estimated_days_min', 'estimated_days_max', 'active'])
            ->withTimestamps();
    }
}
