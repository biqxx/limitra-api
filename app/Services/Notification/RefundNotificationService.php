<?php

namespace App\Services\Notification;

use App\Models\Payment\Refund;
use App\Models\User;
use App\Notifications\AutomaticRefundAttentionNotification;
use App\Notifications\AutomaticRefundInitiatedNotification;
use App\Notifications\AutomaticRefundProcessedNotification;
use App\Notifications\AutomaticRefundStaffAlert;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RefundNotificationService
{
    public function queueInitiated(Refund $refund): void
    {
        if (! $this->markQueued($refund, 'initiated_notification_queued_at')) {
            return;
        }

        $this->afterCommit(function () use ($refund): void {
            $refund = Refund::query()->with(['order', 'user'])->find($refund->id);
            if (! $refund?->user || ! $refund->order) {
                return;
            }

            $refund->user->notify(new AutomaticRefundInitiatedNotification(
                $refund->order_id,
                $refund->order->number,
                $refund->reference,
                $refund->amount,
                $refund->currency,
            ));
        });
    }

    public function queueProcessed(Refund $refund): void
    {
        if (! $this->markQueued($refund, 'processed_notification_queued_at')) {
            return;
        }

        $this->afterCommit(function () use ($refund): void {
            $refund = Refund::query()->with(['order', 'user'])->find($refund->id);
            if (! $refund?->user || ! $refund->order) {
                return;
            }

            $refund->user->notify(new AutomaticRefundProcessedNotification(
                $refund->order_id,
                $refund->order->number,
                $refund->reference,
                $refund->amount,
                $refund->currency,
            ));
        });
    }

    public function queueAttention(Refund $refund): void
    {
        if (! $this->markQueued($refund, 'attention_notification_queued_at')) {
            return;
        }

        $this->afterCommit(function () use ($refund): void {
            $refund = Refund::query()->with(['order', 'user'])->find($refund->id);
            if (! $refund?->user || ! $refund->order) {
                return;
            }

            $refund->user->notify(new AutomaticRefundAttentionNotification(
                $refund->order_id,
                $refund->order->number,
                $refund->reference,
            ));

            $staff = User::query()->whereIn('role', ['admin', 'staff'])->get();
            Notification::send($staff, new AutomaticRefundStaffAlert(
                $refund->order_id,
                $refund->order->number,
                $refund->reference,
                $refund->user->email,
                $refund->failure_message ?: 'The payment provider requires manual review.',
            ));
        });
    }

    private function markQueued(Refund $refund, string $column): bool
    {
        return Refund::query()
            ->whereKey($refund->id)
            ->whereNull($column)
            ->update([$column => now()]) === 1;
    }

    private function afterCommit(Closure $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
