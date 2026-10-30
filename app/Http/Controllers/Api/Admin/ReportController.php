<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Admin\AdminReport;
use App\Models\Affiliate\Affiliate;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = AdminReport::query()->with('user:id,email,username')->latest('id');

        if ($request->filled('type')) {
            $query->where('type', (string) $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:orders,inventory,customers,affiliates'],
            'format' => ['nullable', 'string', 'in:csv,json'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $type = $validated['type'];
        $format = $validated['format'] ?? 'csv';

        $rowCount = match ($type) {
            'orders' => Order::query()
                ->when(! empty($validated['date_from']), fn ($q) => $q->where('created_at', '>=', $validated['date_from']))
                ->when(! empty($validated['date_to']), fn ($q) => $q->where('created_at', '<=', $validated['date_to']))
                ->count(),
            'inventory' => Product::query()->count(),
            'customers' => User::query()->where('role', 'user')->count(),
            'affiliates' => Affiliate::query()->count(),
            default => 0,
        };

        $fileName = "report-{$type}-".now()->format('YmdHis').".{$format}";
        $filePath = "reports/{$fileName}";

        $report = AdminReport::create([
            'user_id' => $request->user('api')?->id,
            'type' => $type,
            'format' => $format,
            'status' => 'completed',
            'parameters' => $validated,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'row_count' => $rowCount,
            'completed_at' => now(),
        ]);

        return $this->success($report, 'Report generated successfully.', 201);
    }

    public function show(AdminReport $report): JsonResponse
    {
        return $this->success([
            'report' => $report->load('user:id,email,username'),
            'download_url' => config('app.url', 'https://limitra.test')."/api/v1/admin/reports/{$report->id}/download",
        ]);
    }
}
