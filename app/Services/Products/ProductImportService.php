<?php

namespace App\Services\Products;

use App\Jobs\Products\ProcessProductImportJob;
use App\Models\Product\Category;
use App\Models\Product\InventoryAdjustment;
use App\Models\Product\Product;
use App\Models\Product\ProductImportJob;
use App\Models\User;
use App\Services\Admin\AuditEventService;
use App\Services\Reports\Formatters\CsvFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductImportService
{
    public function __construct(
        private readonly ProductImportFileReader $fileReader,
        private readonly AuditEventService $audit,
        private readonly CsvFormatter $csvFormatter,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<array<string, mixed>>|null  $inlineRows
     */
    public function initiate(
        User $user,
        array $validated,
        ?UploadedFile $file = null,
        ?array $inlineRows = null,
        ?string $idempotencyKey = null,
        ?Request $request = null,
    ): ProductImportJob {
        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $duplicateSkuPolicy = (string) ($validated['duplicate_sku_policy'] ?? 'update');
        $duplicateSlugPolicy = (string) ($validated['duplicate_slug_policy'] ?? 'generate_suffix');

        $requestHash = hash('sha256', (string) json_encode([
            'dry_run' => $dryRun,
            'duplicate_sku_policy' => $duplicateSkuPolicy,
            'duplicate_slug_policy' => $duplicateSlugPolicy,
            'has_file' => $file !== null,
            'file_name' => $file?->getClientOriginalName(),
            'file_size' => $file?->getSize(),
            'rows' => $inlineRows,
        ]));

        if (! empty($idempotencyKey)) {
            $existing = ProductImportJob::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ($existing->request_hash === $requestHash) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency key collision with different import parameters.'],
                ]);
            }
        }

        $uniqueId = Str::lower(Str::random(12));
        $storedFilePath = null;
        $fileFormat = 'csv';
        $fileSize = null;
        $fileName = 'payload_import.json';
        $totalRows = 0;

        if ($file !== null) {
            $fileName = $file->getClientOriginalName();
            $fileFormat = strtolower($file->getClientOriginalExtension() ?: 'csv');
            $fileSize = $file->getSize();
            $storageName = "import-{$uniqueId}.{$fileFormat}";
            $storedFilePath = $file->storeAs('imports', $storageName, 'local');

            $fullLocalPath = Storage::disk('local')->path($storedFilePath);
            $parsedRows = $this->fileReader->read($fullLocalPath, $fileFormat);
            $totalRows = count($parsedRows);
        } elseif (is_array($inlineRows)) {
            $fileName = "payload-{$uniqueId}.json";
            $fileFormat = 'json';
            $storedFilePath = "imports/{$fileName}";
            Storage::disk('local')->put($storedFilePath, (string) json_encode($inlineRows));
            $fileSize = Storage::disk('local')->size($storedFilePath);
            $totalRows = count($inlineRows);
        } else {
            throw ValidationException::withMessages([
                'file' => ['Please upload a CSV or XLSX file, or provide an array of rows.'],
            ]);
        }

        $job = ProductImportJob::query()->create([
            'user_id' => $user->id,
            'file_name' => $fileName,
            'file_path' => $storedFilePath,
            'file_format' => $fileFormat,
            'file_size_bytes' => $fileSize,
            'status' => ProductImportJob::STATUS_PENDING,
            'dry_run' => $dryRun,
            'duplicate_sku_policy' => $duplicateSkuPolicy,
            'duplicate_slug_policy' => $duplicateSlugPolicy,
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'total_chunks' => (int) ceil(max($totalRows, 1) / 50),
            'current_chunk' => 0,
            'retry_count' => 0,
        ]);

        $this->audit->record(
            action: 'product_import.requested',
            actor: $user,
            subject: $job,
            metadata: [
                'file_name' => $fileName,
                'dry_run' => $dryRun,
                'total_rows' => $totalRows,
                'idempotency_key' => $idempotencyKey,
            ],
            request: $request,
        );

        ProcessProductImportJob::dispatch($job->id);

        return $job;
    }

    public function process(ProductImportJob $job): void
    {
        if ($job->isCancelled() || $job->isCompleted()) {
            return;
        }

        $job->update([
            'status' => ProductImportJob::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            $rows = $this->loadRows($job);
            $total = count($rows);

            if ($total !== $job->total_rows) {
                $job->update([
                    'total_rows' => $total,
                    'total_chunks' => (int) ceil(max($total, 1) / 50),
                ]);
            }

            $successful = 0;
            $failed = 0;
            $errors = [];
            $processed = 0;

            $chunks = array_chunk($rows, 50, true);

            foreach ($chunks as $chunkIndex => $chunk) {
                // Check if cancelled between chunks
                if ($job->fresh()?->isCancelled()) {
                    return;
                }

                foreach ($chunk as $originalIndex => $row) {
                    $rowNum = $originalIndex + 2; // Row 1 is header

                    $validator = Validator::make($row, [
                        'name' => ['required', 'string', 'max:255'],
                        'price' => ['required', 'numeric', 'min:0'],
                        'stock' => ['nullable', 'integer', 'min:0'],
                        'sku' => ['nullable', 'string', 'max:100'],
                        'category_id' => ['nullable', 'exists:categories,id'],
                        'brand' => ['nullable', 'string', 'max:100'],
                        'description' => ['nullable', 'string'],
                    ]);

                    if ($validator->fails()) {
                        $failed++;
                        $errors[] = [
                            'row' => $rowNum,
                            'errors' => $validator->errors()->all(),
                        ];
                        $processed++;

                        continue;
                    }

                    if ($job->dry_run) {
                        $successful++;
                        $processed++;

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($row, $job, $rowNum, &$errors, &$failed, &$successful): void {
                            $sku = ! empty($row['sku']) ? trim((string) $row['sku']) : 'SKU-'.strtoupper(Str::random(8));
                            $existingProduct = Product::query()->where('sku', $sku)->first();

                            if ($existingProduct) {
                                if ($job->duplicate_sku_policy === 'skip') {
                                    $successful++;

                                    return;
                                }

                                if ($job->duplicate_sku_policy === 'error') {
                                    $failed++;
                                    $errors[] = [
                                        'row' => $rowNum,
                                        'errors' => ["Product with SKU '{$sku}' already exists."],
                                    ];

                                    return;
                                }

                                // Policy 'update'
                                $oldStock = (int) $existingProduct->stock;
                                $newStock = isset($row['stock']) && $row['stock'] !== '' ? (int) $row['stock'] : $oldStock;

                                $existingProduct->update([
                                    'name' => $row['name'],
                                    'price' => (float) $row['price'],
                                    'stock' => $newStock,
                                    'brand' => $row['brand'] ?? $existingProduct->brand,
                                    'description' => $row['description'] ?? $existingProduct->description,
                                    'status' => 'draft',
                                    'is_active' => false,
                                ]);

                                if ($newStock !== $oldStock) {
                                    InventoryAdjustment::query()->create([
                                        'product_id' => $existingProduct->id,
                                        'adjusted_by' => $job->user_id,
                                        'quantity_delta' => $newStock - $oldStock,
                                        'quantity_before' => $oldStock,
                                        'quantity_after' => $newStock,
                                        'reason' => 'supplier_receipt',
                                        'notes' => "Stock adjustment from product import job #{$job->id}",
                                        'created_at' => now(),
                                    ]);
                                }

                                $successful++;

                                return;
                            }

                            // New product creation
                            $slug = Str::slug($row['name']);
                            if (Product::query()->where('slug', $slug)->exists()) {
                                if ($job->duplicate_slug_policy === 'error') {
                                    $failed++;
                                    $errors[] = [
                                        'row' => $rowNum,
                                        'errors' => ["Product slug '{$slug}' already exists."],
                                    ];

                                    return;
                                }
                                $slug .= '-'.strtolower(Str::random(4));
                            }

                            $categoryId = ! empty($row['category_id'])
                                ? (int) $row['category_id']
                                : (Category::query()->first()?->id ?? Category::query()->create(['name' => 'General', 'slug' => 'general'])->id);

                            $initialStock = isset($row['stock']) && $row['stock'] !== '' ? (int) $row['stock'] : 0;

                            $newProduct = Product::query()->create([
                                'category_id' => $categoryId,
                                'name' => $row['name'],
                                'slug' => $slug,
                                'sku' => $sku,
                                'price' => (float) $row['price'],
                                'stock' => $initialStock,
                                'brand' => $row['brand'] ?? null,
                                'description' => $row['description'] ?? null,
                                'status' => 'draft',
                                'is_active' => false,
                            ]);

                            if ($initialStock > 0) {
                                InventoryAdjustment::query()->create([
                                    'product_id' => $newProduct->id,
                                    'adjusted_by' => $job->user_id,
                                    'quantity_delta' => $initialStock,
                                    'quantity_before' => 0,
                                    'quantity_after' => $initialStock,
                                    'reason' => 'supplier_receipt',
                                    'notes' => "Initial inventory from product import job #{$job->id}",
                                    'created_at' => now(),
                                ]);
                            }

                            $successful++;
                        });
                    } catch (Throwable $e) {
                        Log::error('Row processing failed in product import', [
                            'job_id' => $job->id,
                            'row' => $rowNum,
                            'error' => $e->getMessage(),
                        ]);

                        $failed++;
                        $errors[] = [
                            'row' => $rowNum,
                            'errors' => ['Failed to save product record.'],
                        ];
                    }

                    $processed++;
                }

                $job->update([
                    'processed_rows' => $processed,
                    'successful_rows' => $successful,
                    'failed_rows' => $failed,
                    'errors' => $errors,
                    'current_chunk' => $chunkIndex + 1,
                ]);
            }

            // Generate error report if there are failures
            $errorReportPath = null;
            if ($failed > 0 && ! empty($errors)) {
                $errorReportPath = $this->generateErrorReportCsv($job, $errors);
            }

            $finalStatus = ($failed > 0 && $successful === 0)
                ? ProductImportJob::STATUS_FAILED
                : ProductImportJob::STATUS_COMPLETED;

            $job->update([
                'status' => $finalStatus,
                'completed_at' => now(),
                'error_report_path' => $errorReportPath,
            ]);

            $this->audit->record(
                action: 'product_import.completed',
                actor: $job->user,
                subject: $job,
                metadata: [
                    'status' => $finalStatus,
                    'successful_rows' => $successful,
                    'failed_rows' => $failed,
                    'total_rows' => $total,
                ],
            );
        } catch (Throwable $e) {
            Log::error('Product import job failed fatally', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            $job->update([
                'status' => ProductImportJob::STATUS_FAILED,
            ]);
        }
    }

    public function cancel(ProductImportJob $job, User $actor, ?Request $request = null): ProductImportJob
    {
        if ($job->isCompleted() || $job->isFailed()) {
            throw ValidationException::withMessages([
                'status' => ['Cannot cancel an import job that is already completed or failed.'],
            ]);
        }

        $job->update([
            'status' => ProductImportJob::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->audit->record(
            action: 'product_import.cancelled',
            actor: $actor,
            subject: $job,
            request: $request,
        );

        return $job;
    }

    public function retry(ProductImportJob $job, User $actor, ?Request $request = null): ProductImportJob
    {
        if (! $job->isFailed() && ! $job->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['Only failed or cancelled import jobs can be retried.'],
            ]);
        }

        $job->update([
            'status' => ProductImportJob::STATUS_PENDING,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
            'started_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'retry_count' => $job->retry_count + 1,
        ]);

        $this->audit->record(
            action: 'product_import.retried',
            actor: $actor,
            subject: $job,
            metadata: [
                'retry_count' => $job->retry_count,
            ],
            request: $request,
        );

        ProcessProductImportJob::dispatch($job->id);

        return $job;
    }

    public function downloadErrorReport(ProductImportJob $job, User $actor, Request $request): StreamedResponse
    {
        if (empty($job->error_report_path) || ! Storage::disk('local')->exists($job->error_report_path)) {
            abort(404, 'No error report available for this import job.');
        }

        $this->audit->record(
            action: 'product_import.error_report_downloaded',
            actor: $actor,
            subject: $job,
            request: $request,
        );

        return Storage::disk('local')->download(
            $job->error_report_path,
            "import-errors-job-{$job->id}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(ProductImportJob $job): array
    {
        if (empty($job->file_path) || ! Storage::disk('local')->exists($job->file_path)) {
            return [];
        }

        $fullPath = Storage::disk('local')->path($job->file_path);

        if ($job->file_format === 'json') {
            $content = file_get_contents($fullPath);
            $decoded = json_decode((string) $content, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $this->fileReader->read($fullPath, $job->file_format ?: 'csv');
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    private function generateErrorReportCsv(ProductImportJob $job, array $errors): string
    {
        $rows = [];
        foreach ($errors as $errorItem) {
            $rowNum = $errorItem['row'] ?? '';
            $errList = is_array($errorItem['errors'] ?? null)
                ? implode('; ', $errorItem['errors'])
                : (string) ($errorItem['errors'] ?? '');

            $rows[] = [
                'row' => $rowNum,
                'error' => $errList,
            ];
        }

        $csv = $this->csvFormatter->format(['row', 'error'], $rows);
        $path = "imports/errors/job_{$job->id}_errors.csv";
        Storage::disk('local')->put($path, $csv);

        return $path;
    }
}
