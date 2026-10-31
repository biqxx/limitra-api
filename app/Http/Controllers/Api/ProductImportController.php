<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Admin\ProductImportRequest;
use App\Models\Product\Product;
use App\Models\Product\ProductImportJob;
use App\Services\Products\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ProductImportController extends BaseController
{
    public function store(ProductImportRequest $request, ProductImportService $importService): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $user = $request->user('api');

        $job = $importService->initiate(
            user: $user,
            validated: $request->validated(),
            file: $request->file('file'),
            inlineRows: $request->input('rows'),
            idempotencyKey: $idempotencyKey ? (string) $idempotencyKey : null,
            request: $request,
        );

        $status = $job->wasRecentlyCreated ? 201 : 200;
        $message = $job->wasRecentlyCreated ? 'Product import queued for processing.' : 'Product import replayed.';

        return $this->success($job->fresh(), $message, $status);
    }

    public function show(Request $request, ProductImportJob $job): JsonResponse
    {
        $this->authorizeView($request, $job);

        $errorReportUrl = null;
        if (! empty($job->error_report_path)) {
            $errorReportUrl = URL::temporarySignedRoute(
                'products.imports.errors',
                now()->addMinutes(30),
                ['job' => $job->id],
            );
        }

        return $this->success([
            'job' => $job->load('user:id,username,email'),
            'error_report_url' => $errorReportUrl,
        ]);
    }

    public function cancel(Request $request, ProductImportJob $job, ProductImportService $importService): JsonResponse
    {
        $this->authorizeManage($request, $job);

        $cancelledJob = $importService->cancel($job, $request->user('api'), $request);

        return $this->success($cancelledJob, 'Import job cancelled.');
    }

    public function retry(Request $request, ProductImportJob $job, ProductImportService $importService): JsonResponse
    {
        $this->authorizeManage($request, $job);

        $retriedJob = $importService->retry($job, $request->user('api'), $request);

        return $this->success($retriedJob, 'Import job queued for retry.');
    }

    public function downloadErrors(Request $request, ProductImportJob $job, ProductImportService $importService): Response
    {
        $this->authorizeView($request, $job);

        return $importService->downloadErrorReport($job, $request->user('api') ?? $job->user, $request);
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

    private function authorizeView(Request $request, ProductImportJob $job): void
    {
        $user = $request->user('api');
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->can('create', Product::class) || $job->user_id === $user->id) {
            return;
        }

        abort(403, 'Unauthorized to view this import job.');
    }

    private function authorizeManage(Request $request, ProductImportJob $job): void
    {
        $user = $request->user('api');
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->can('create', Product::class) || $job->user_id === $user->id) {
            return;
        }

        abort(403, 'Unauthorized to modify this import job.');
    }
}
