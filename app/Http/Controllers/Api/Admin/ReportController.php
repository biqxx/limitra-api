<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\GenerateReportRequest;
use App\Models\Admin\AdminReport;
use App\Services\Reports\AdminReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

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

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

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

    public function store(GenerateReportRequest $request, AdminReportService $reportService): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $user = $request->user('api');

        $report = $reportService->requestReport(
            user: $user,
            validated: $request->validated(),
            idempotencyKey: $idempotencyKey ? (string) $idempotencyKey : null,
            request: $request,
        );

        $status = $report->wasRecentlyCreated ? 201 : 200;
        $message = $report->wasRecentlyCreated ? 'Report queued for generation.' : 'Report request replayed.';

        return $this->success($report, $message, $status);
    }

    public function show(AdminReport $report): JsonResponse
    {
        $downloadUrl = null;

        if ($report->isCompleted() && ! $report->isExpired()) {
            $downloadUrl = URL::temporarySignedRoute(
                'admin.reports.download',
                now()->addMinutes(15),
                ['report' => $report->id],
            );
        }

        return $this->success([
            'report' => $report->load('user:id,email,username'),
            'download_url' => $downloadUrl,
        ]);
    }

    public function retry(AdminReport $report, AdminReportService $reportService, Request $request): JsonResponse
    {
        $retriedReport = $reportService->retry($report, $request->user('api'), $request);

        return $this->success($retriedReport, 'Report queued for retry.');
    }

    public function download(Request $request, AdminReport $report, AdminReportService $reportService): Response
    {
        return $reportService->download(
            report: $report,
            user: $request->user('api') ?? $report->user,
            request: $request,
        );
    }
}
