<?php

namespace App\Services\Maintenance;

use App\Models\Commerce\CheckoutQuote;
use App\Models\User\AuthSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class MaintenancePruningService
{
    public function pruneAuthSessions(): int
    {
        $cutoff = now()->subDays(max(0, (int) config('maintenance.auth_session_retention_days', 30)));
        $query = AuthSession::query()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('expires_at', '<=', $cutoff)
                    ->orWhere('revoked_at', '<=', $cutoff);
            });

        return $this->deleteEloquentBatch($query);
    }

    public function pruneCheckoutQuotes(): int
    {
        $cutoff = now()->subHours(max(0, (int) config('maintenance.checkout_quote_retention_hours', 24)));
        $query = CheckoutQuote::query()
            ->whereNull('consumed_at')
            ->where('expires_at', '<=', $cutoff)
            ->whereDoesntHave('order', function (Builder $query): void {
                $query->withTrashed();
            });

        return $this->deleteEloquentBatch($query);
    }

    /**
     * @return array<string, int>
     */
    public function pruneRawAnalytics(): array
    {
        $cutoff = now()->subDays(max(1, (int) config('maintenance.analytics_retention_days', 90)));
        $tables = [
            'page_views',
            'product_views',
            'cart_events',
            'order_events',
            'traffic_sources',
            'analytics_events',
        ];

        return DB::transaction(function () use ($cutoff, $tables): array {
            $deleted = [];

            foreach ($tables as $table) {
                $deleted[$table] = $this->deleteQueryBatch(
                    DB::table($table)->where('created_at', '<=', $cutoff),
                );
            }

            return $deleted;
        });
    }

    private function deleteEloquentBatch(Builder $query): int
    {
        $ids = $query->orderBy('id')->limit($this->batchSize())->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return $query->getModel()->newQuery()->whereKey($ids->all())->delete();
    }

    private function deleteQueryBatch(QueryBuilder $query): int
    {
        $ids = (clone $query)->orderBy('id')->limit($this->batchSize())->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table($query->from)->whereIn('id', $ids)->delete();
    }

    private function batchSize(): int
    {
        return max(1, (int) config('maintenance.prune_batch_size', 1000));
    }
}
