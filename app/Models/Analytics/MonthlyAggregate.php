<?php

namespace App\Models\Analytics;

use Illuminate\Database\Eloquent\Model;

class MonthlyAggregate extends Model
{
    protected $table = 'analytics_monthly_aggregates';

    protected $fillable = [
        'year',
        'month',
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
        'returning_users',
        'avg_order_value',
        'cart_abandonment_rate',
        'conversion_rate',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
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
            'returning_users' => 'integer',
            'avg_order_value' => 'decimal:2',
            'cart_abandonment_rate' => 'decimal:2',
            'conversion_rate' => 'decimal:2',
        ];
    }
}
