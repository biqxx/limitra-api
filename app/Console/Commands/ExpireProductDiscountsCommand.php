<?php

namespace App\Console\Commands;

use App\Models\Product\Product;
use App\Services\Catalog\CatalogCache;
use Illuminate\Console\Command;

class ExpireProductDiscountsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:expire-discounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Revert expired product discounts back to compare_at_price and clear discount schedule.';

    /**
     * Execute the console command.
     */
    public function handle(CatalogCache $catalogCache): int
    {
        $now = now();
        $expiredCount = 0;

        Product::query()
            ->whereNotNull('discount_ends_at')
            ->where('discount_ends_at', '<=', $now)
            ->chunkById(100, function ($products) use (&$expiredCount): void {
                foreach ($products as $product) {
                    $revertedPrice = $product->compare_at_price && (float) $product->compare_at_price > (float) $product->price
                        ? $product->compare_at_price
                        : $product->price;

                    $product->update([
                        'price' => $revertedPrice,
                        'compare_at_price' => null,
                        'discount_starts_at' => null,
                        'discount_ends_at' => null,
                    ]);

                    $expiredCount++;
                }
            });

        if ($expiredCount > 0) {
            $catalogCache->invalidateProducts();
        }

        $this->info("Reverted {$expiredCount} expired product discount(s).");

        return self::SUCCESS;
    }
}
