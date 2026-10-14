<?php

namespace App\Jobs;

use App\Models\Product\ProductSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchProductRepricing implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        ProductSource::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($sources): void {
                foreach ($sources as $source) {
                    RepriceProductSource::dispatch($source->id);
                }
            });
    }
}
