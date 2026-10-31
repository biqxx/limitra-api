<?php

namespace Database\Factories\Product;

use App\Models\Product\Product;
use App\Models\Product\ProductSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductSource> */
class ProductSourceFactory extends Factory
{
    protected $model = ProductSource::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'supplier' => 'ebay',
            'external_product_id' => fake()->unique()->uuid(),
            'supplier_price' => 100,
            'supplier_currency' => 'USD',
            'available' => true,
            'weight_lb' => 1,
            'delivery_days_min' => 2,
            'delivery_days_max' => 5,
            'source_snapshot' => [],
            'sync_status' => 'needs_review',
            'pricing_status' => 'pending_inputs',
            'price_review_required' => true,
        ];
    }
}
