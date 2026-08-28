<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryZone extends Model
{
    protected $fillable = ['name', 'country', 'states', 'cities', 'priority', 'active'];

    protected function casts(): array
    {
        return ['states' => 'array', 'cities' => 'array', 'active' => 'boolean'];
    }

    public function methods(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryMethod::class, 'delivery_zone_method')
            ->withPivot(['fee', 'free_shipping_threshold', 'minimum_order', 'estimated_days_min', 'estimated_days_max', 'active'])
            ->withTimestamps();
    }

    public function pickupLocations(): HasMany
    {
        return $this->hasMany(PickupLocation::class);
    }
}
