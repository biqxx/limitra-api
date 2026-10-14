<?php

namespace Database\Factories\Product;

use App\Models\Product\ProductPriceQuote;
use App\Models\Product\ProductSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductPriceQuote> */
class ProductPriceQuoteFactory extends Factory
{
    protected $model = ProductPriceQuote::class;

    public function definition(): array
    {
        return [
            'product_source_id' => ProductSource::factory(),
            'status' => 'proposed',
            'supplier_price' => 100,
            'supplier_currency' => 'USD',
            'weight_lb' => 1,
            'delivery_days_min' => 2,
            'delivery_days_max' => 5,
            'proposed_price_ngn' => 528000,
            'cost_breakdown' => ['final_price_ngn' => 528000],
            'rules_snapshot' => ['pricing.profit_markup_basis_points' => 20000],
            'rules_version' => hash('sha256', 'factory'),
            'quoted_at' => now(),
        ];
    }
}
