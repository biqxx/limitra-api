<?php

namespace App\Jobs\Products;

use App\Models\Product\ProductImportJob;
use App\Services\Products\ProductImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessProductImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 600;

    public function __construct(public int $importJobId)
    {
        $this->onQueue('default');
    }

    public function handle(ProductImportService $importService): void
    {
        $job = ProductImportJob::query()->find($this->importJobId);
        if (! $job || $job->isCompleted() || $job->isCancelled()) {
            return;
        }

        $importService->process($job);
    }

    public function failed(?Throwable $exception): void
    {
        $job = ProductImportJob::query()->find($this->importJobId);
        if ($job && ! $job->isCompleted()) {
            $job->update([
                'status' => ProductImportJob::STATUS_FAILED,
            ]);
        }

        if ($exception) {
            report($exception);
        }
    }
}
