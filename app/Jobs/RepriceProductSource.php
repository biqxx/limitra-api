<?php

namespace App\Jobs;

use App\Models\Product\ProductSource;
use App\Services\Sourcing\ProductPricingService;
use App\Services\Sourcing\SupplierServiceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RepriceProductSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $productSourceId) {}

    public function handle(SupplierServiceClient $supplier, ProductPricingService $pricing): void
    {
        $source = ProductSource::query()->with(['product', 'approvedPriceQuote'])->find($this->productSourceId);

        if (! $source) {
            return;
        }

        $supplierProduct = $supplier->details($source->supplier, $source->external_product_id);
        $pricing->refreshFromSupplier($source, $supplierProduct);
    }
}
