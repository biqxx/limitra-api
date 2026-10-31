<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\AdminReportService;
use Illuminate\Console\Command;

class PruneExpiredReportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune expired admin reports and delete their stored files';

    /**
     * Execute the console command.
     */
    public function handle(AdminReportService $reportService): int
    {
        $this->info('Pruning expired reports...');

        $pruned = $reportService->pruneExpired();

        $this->info("Successfully pruned {$pruned} expired report(s).");

        return self::SUCCESS;
    }
}
