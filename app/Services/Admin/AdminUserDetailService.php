<?php

namespace App\Services\Admin;

use App\Models\Admin\AuditEvent;
use App\Models\Order\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AdminUserDetailService
{
    /**
     * @return array{
     *     user: User,
     *     summary: array{
     *         orders_count: int,
     *         qualifying_orders_count: int,
     *         last_order_at: mixed,
     *         lifetime_value: Collection<int, array{currency: string, amount: string}>
     *     },
     *     recent_orders: Collection<int, Order>,
     *     recent_activity: Collection<int, AuditEvent>
     * }
     */
    public function get(User $user): array
    {
        $user->load(['profile', 'roles.permissions']);

        $orderSummary = $user->orders()
            ->toBase()
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COUNT(CASE WHEN status != 'cancelled' THEN 1 END) as qualifying_orders_count")
            ->selectRaw('MAX(created_at) as last_order_at')
            ->first();

        $lifetimeValue = $user->orders()
            ->toBase()
            ->where('status', '!=', 'cancelled')
            ->select('currency')
            ->selectRaw('SUM(grand_total) as amount')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn (object $total): array => [
                'currency' => Str::upper((string) $total->currency),
                'amount' => number_format((float) $total->amount, 2, '.', ''),
            ]);

        $recentOrders = $user->orders()
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get([
                'id',
                'user_id',
                'number',
                'currency',
                'grand_total',
                'status',
                'payment_status',
                'fulfilment_status',
                'created_at',
            ]);

        $recentActivity = AuditEvent::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->with('actor:id,username')
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'actor_id', 'action', 'subject_type', 'subject_id', 'reason', 'created_at']);

        return [
            'user' => $user,
            'summary' => [
                'orders_count' => (int) ($orderSummary->orders_count ?? 0),
                'qualifying_orders_count' => (int) ($orderSummary->qualifying_orders_count ?? 0),
                'last_order_at' => isset($orderSummary->last_order_at)
                    ? CarbonImmutable::parse($orderSummary->last_order_at)
                    : null,
                'lifetime_value' => $lifetimeValue,
            ],
            'recent_orders' => $recentOrders,
            'recent_activity' => $recentActivity,
        ];
    }
}
