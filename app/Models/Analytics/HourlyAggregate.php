<?php

namespace App\Models\Analytics;

use Illuminate\Database\Eloquent\Model;

class HourlyAggregate extends Model
{
    protected $table = 'analytics_hourly_aggregates';

    protected $fillable = [
        'hour_at',
        'visits',
        'unique_visitors',
        'page_views',
        'product_views',
        'add_to_carts',
        'checkouts_started',
        'checkouts_completed',
        'orders_placed',
        'orders_revenue',
        'new_users',
    ];

    protected function casts(): array
    {
        return [
            'hour_at' => 'datetime',
            'visits' => 'integer',
            'unique_visitors' => 'integer',
            'page_views' => 'integer',
            'product_views' => 'integer',
            'add_to_carts' => 'integer',
            'checkouts_started' => 'integer',
            'checkouts_completed' => 'integer',
            'orders_placed' => 'integer',
            'orders_revenue' => 'decimal:2',
            'new_users' => 'integer',
        ];
    }
}
