<?php

namespace App\Jobs\Reports;

use App\Models\Admin\AdminReport;
use App\Services\Reports\AdminReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAdminReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 300;

    public function __construct(public int $reportId)
    {
        $this->onQueue('reports');
    }

    public function handle(AdminReportService $reportService): void
    {
        $report = AdminReport::query()->find($this->reportId);
        if (! $report || $report->status === AdminReport::STATUS_COMPLETED || $report->status === AdminReport::STATUS_EXPIRED) {
            return;
        }

        $reportService->generate($report);
    }

    public function failed(?Throwable $exception): void
    {
        $report = AdminReport::query()->find($this->reportId);
        if ($report && $report->status !== AdminReport::STATUS_COMPLETED) {
            $report->update([
                'status' => AdminReport::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => 'Report generation encountered an error. Please retry.',
            ]);
        }

        if ($exception) {
            report($exception);
        }
    }
}
