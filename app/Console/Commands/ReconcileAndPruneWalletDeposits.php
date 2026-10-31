<?php

namespace App\Console\Commands;

use App\Services\Wallet\WalletDepositSettlementService;
use Illuminate\Console\Command;

class ReconcileAndPruneWalletDeposits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wallet:reconcile-deposits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile and prune expired or abandoned pending wallet deposits';

    /**
     * Execute the console command.
     */
    public function handle(WalletDepositSettlementService $settlement): int
    {
        $count = $settlement->pruneExpiredDeposits();
        $this->info("Pruned {$count} expired pending wallet deposit(s).");

        return self::SUCCESS;
    }
}
