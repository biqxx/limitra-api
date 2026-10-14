<?php

namespace App\Jobs;

use App\Models\Product\ProductSource;
use App\Models\Product\SourcingJob;
use App\Services\Sourcing\ProductContentEnhancer;
use App\Services\Sourcing\SourcingCategoryResolver;
use App\Services\Sourcing\SupplierProductImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessSourcingProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $sourcingJobId)
    {
        $this->onQueue('ai');
    }

    public function handle(
        ProductContentEnhancer $enhancer,
        SupplierProductImportService $importer,
        SourcingCategoryResolver $categoryResolver,
    ): void {
        $job = SourcingJob::query()->with('user')->find($this->sourcingJobId);

        if (! $job || $job->status === 'completed') {
            return;
        }

        $existing = ProductSource::query()
            ->where('supplier', $job->supplier)
            ->where('external_product_id', $job->external_product_id)
            ->first();

        if ($existing) {
            $this->complete($job, $existing->product_id, null, null, null);

            return;
        }

        if (! $job->user) {
            throw new RuntimeException('The admin who queued this product no longer exists.');
        }

        $job->update([
            'status' => 'processing',
            'attempts' => $job->attempts + 1,
            'error' => null,
            'started_at' => $job->started_at ?? now(),
        ]);

        try {
            $result = $enhancer->enhance($job->supplier, $job->external_product_id);
            $enhanced = $result['enhanced'];
            $warnings = [];

            if (empty($enhanced['category_id'])) {
                $fallbackCategory = $categoryResolver->fallback();
                $enhanced['category_id'] = $fallbackCategory->id;
                $enhanced['subcategory_id'] = null;
                $warnings[] = 'AI could not match this product to the current catalog. It was placed in Uncategorized; choose the correct category before activating the draft.';
            }

            $attributes = [
                'source' => $job->supplier,
                'external_product_id' => $job->external_product_id,
                'category_id' => $enhanced['category_id'],
                'subcategory_id' => $enhanced['subcategory_id'] ?? null,
                'name' => $enhanced['name'],
                'brand' => $enhanced['brand'] ?: null,
                'description' => $enhanced['description'] ?: null,
                'specifications' => $enhanced['specifications'] ?? [],
            ];
            $source = $importer->importResolved(
                $attributes,
                $job->user,
                $result['supplier_product'],
            );

            if ($source->pricing_status !== 'pending_approval') {
                $warnings[] = 'Supplier pricing data is incomplete or blocked. Complete the pricing review before activating this draft.';
            } else {
                $warnings[] = 'The selling price was calculated and requires administrator approval before activation.';
            }

            $this->complete(
                $job,
                $source->product_id,
                $result['supplier_product'],
                $enhanced,
                $warnings !== [] ? implode(' ', $warnings) : null,
            );
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'queued',
                'error' => Str::limit($exception->getMessage(), 2000),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        SourcingJob::query()->whereKey($this->sourcingJobId)->update([
            'status' => 'failed',
            'error' => $exception ? Str::limit($exception->getMessage(), 2000) : 'The sourcing job failed.',
            'completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $supplierProduct @param array<string, mixed>|null $enhanced */
    private function complete(
        SourcingJob $job,
        int $productId,
        ?array $supplierProduct,
        ?array $enhanced,
        ?string $warning,
    ): void {
        $job->update([
            'product_id' => $productId,
            'status' => 'completed',
            'error' => null,
            'warning' => $warning,
            'supplier_product' => $supplierProduct,
            'enhanced_content' => $enhanced,
            'completed_at' => now(),
        ]);
    }
}
