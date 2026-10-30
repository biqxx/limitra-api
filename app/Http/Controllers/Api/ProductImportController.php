<?php

namespace App\Http\Controllers\Api;

use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductImportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductImportController extends BaseController
{
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $dryRun = $request->boolean('dry_run', false);

        $rows = [];
        $fileName = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();
            $path = $file->getRealPath();

            if (($handle = fopen($path, 'r')) !== false) {
                $header = fgetcsv($handle);
                if ($header !== false) {
                    $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) === count($header)) {
                            $rows[] = array_combine($header, $row);
                        }
                    }
                }
                fclose($handle);
            }
        } elseif ($request->filled('rows') && is_array($request->input('rows'))) {
            $rows = $request->input('rows');
            $fileName = 'payload_import.json';
        } else {
            return $this->error('Please upload a CSV file or provide a rows array.', 422);
        }

        $job = ProductImportJob::create([
            'user_id' => $request->user('api')?->id,
            'file_name' => $fileName,
            'status' => 'processing',
            'dry_run' => $dryRun,
            'total_rows' => count($rows),
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'errors' => [],
        ]);

        $successful = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // header is row 1

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

                continue;
            }

            if (! $dryRun) {
                try {
                    DB::transaction(function () use ($row): void {
                        $sku = ! empty($row['sku']) ? $row['sku'] : 'SKU-'.strtoupper(Str::random(8));
                        $slug = Str::slug($row['name']);
                        if (Product::where('slug', $slug)->exists()) {
                            $slug .= '-'.strtolower(Str::random(4));
                        }

                        $categoryId = ! empty($row['category_id'])
                            ? (int) $row['category_id']
                            : (Category::first()?->id ?? Category::create(['name' => 'General', 'slug' => 'general'])->id);

                        Product::updateOrCreate(
                            ['sku' => $sku],
                            [
                                'category_id' => $categoryId,
                                'name' => $row['name'],
                                'slug' => $slug,
                                'price' => (float) $row['price'],
                                'stock' => (int) ($row['stock'] ?? 0),
                                'brand' => $row['brand'] ?? null,
                                'description' => $row['description'] ?? null,
                                'status' => 'active',
                            ]
                        );
                    });
                    $successful++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'row' => $rowNum,
                        'errors' => [$e->getMessage()],
                    ];
                }
            } else {
                $successful++;
            }
        }

        $job->update([
            'status' => $failed > 0 && $successful === 0 ? 'failed' : 'completed',
            'processed_rows' => count($rows),
            'successful_rows' => $successful,
            'failed_rows' => $failed,
            'errors' => $errors,
        ]);

        return $this->success($job->fresh(), 'Product import processed.', 201);
    }

    public function show(ProductImportJob $job): JsonResponse
    {
        return $this->success($job);
    }

    public function template(): JsonResponse
    {
        return $this->success([
            'columns' => ['name', 'price', 'stock', 'sku', 'category_id', 'brand', 'description'],
            'sample_row' => [
                'name' => 'Premium Wireless Headphones',
                'price' => '29999.00',
                'stock' => '50',
                'sku' => 'HDPH-WL-001',
                'category_id' => '1',
                'brand' => 'Sony',
                'description' => 'High quality noise cancelling headphones.',
            ],
        ], 'Import template columns retrieved.');
    }
}
