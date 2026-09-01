<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Jobs\ReconcileAutomaticRefund;
use App\Models\Payment\Refund;
use App\Services\Notification\RefundNotificationService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundReconciliationService
{
    private const ACTIVE_STATUSES = ['pending', 'processing'];

    public function __construct(
        private readonly PaystackService $paystack,
        private readonly RefundSettlementService $settlement,
        private readonly BusinessSettingsService $settings,
        private readonly RefundNotificationService $notifications,
    ) {}

    public function dispatchDue(int $limit = 100): int
    {
        $now = now();
        $initialCutoff = $now->copy()->subMinutes($this->delayMinutes());
        $claimUntil = $now->copy()->addMinutes($this->intervalMinutes());
        $ids = $this->dueQuery($now, $initialCutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $dispatched = 0;

        foreach ($ids as $id) {
            $claimed = $this->dueQuery($now, $initialCutoff)
                ->whereKey($id)
                ->update(['next_reconciliation_at' => $claimUntil]);

            if ($claimed !== 1) {
                continue;
            }

            ReconcileAutomaticRefund::dispatch((int) $id);
            $dispatched++;
        }

        return $dispatched;
    }

    public function reconcile(int $refundId): void
    {
        $refund = Refund::query()->with(['payment', 'order'])->find($refundId);

        if (! $refund
            || $refund->source !== 'late_payment'
            || ! in_array($refund->status, self::ACTIVE_STATUSES, true)) {
            return;
        }

        try {
            $providerData = $this->lookup($refund);
        } catch (PaymentGatewayException $exception) {
            report($exception);
            $this->recordUnresolved($refund->id);

            return;
        }

        if (! $providerData) {
            $this->recordUnresolved($refund->id);

            return;
        }

        $recorded = Refund::query()
            ->whereKey($refund->id)
            ->where('source', 'late_payment')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update([
                'reconciliation_attempts' => DB::raw('reconciliation_attempts + 1'),
                'last_reconciled_at' => now(),
            ]);

        if ($recorded === 1) {
            $this->settlement->apply($refund, $providerData);
        }
    }

    private function dueQuery(Carbon $now, Carbon $initialCutoff): Builder
    {
        return Refund::query()
            ->where('source', 'late_payment')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(function (Builder $query) use ($now, $initialCutoff): void {
                $query->where('next_reconciliation_at', '<=', $now)
                    ->orWhere(function (Builder $missingSchedule) use ($initialCutoff): void {
                        $missingSchedule->whereNull('next_reconciliation_at')
                            ->where('created_at', '<=', $initialCutoff);
                    });
            });
    }

    /** @return array<string, mixed>|null */
    private function lookup(Refund $refund): ?array
    {
        if ($refund->provider_refund_id) {
            $providerData = $this->paystack->fetchRefund($refund->provider_refund_id);
        } else {
            $providerData = $this->matchListedRefund(
                $refund,
                $this->paystack->listRefunds($refund->payment->reference),
            );
        }

        if (! $providerData) {
            return null;
        }

        $providerData['transaction_reference'] ??= $refund->payment->reference;

        return $providerData;
    }

    /**
     * @param  array<int, array<string, mixed>>  $providerRefunds
     * @return array<string, mixed>|null
     */
    private function matchListedRefund(Refund $refund, array $providerRefunds): ?array
    {
        $candidates = collect($providerRefunds)
            ->filter(fn (mixed $candidate): bool => is_array($candidate))
            ->filter(function (array $candidate) use ($refund): bool {
                if ((int) ($candidate['amount'] ?? -1) !== $refund->amount_minor
                    || strtoupper((string) ($candidate['currency'] ?? '')) !== strtoupper($refund->currency)) {
                    return false;
                }

                $transaction = $candidate['transaction'] ?? null;
                if (is_array($transaction)
                    && isset($transaction['reference'])
                    && $transaction['reference'] !== $refund->payment->reference) {
                    return false;
                }

                $createdAt = $candidate['createdAt'] ?? $candidate['created_at'] ?? null;
                if ($createdAt) {
                    try {
                        if (Carbon::parse($createdAt)->lt($refund->created_at->copy()->subMinutes(10))) {
                            return false;
                        }
                    } catch (\Throwable) {
                        return false;
                    }
                }

                return true;
            })
            ->values();

        if ($refund->provider_reference) {
            $referenceMatch = $candidates->first(fn (array $candidate): bool => ($candidate['refund_reference'] ?? $candidate['reference'] ?? null) === $refund->provider_reference
            );

            if ($referenceMatch) {
                return $referenceMatch;
            }
        }

        $orderMatches = $candidates->filter(fn (array $candidate): bool => Str::contains((string) ($candidate['merchant_note'] ?? ''), $refund->order->number)
        )->values();

        if ($orderMatches->count() === 1) {
            return $orderMatches->first();
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function recordUnresolved(int $refundId): void
    {
        $requiresAttention = DB::transaction(function () use ($refundId): bool {
            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->first();

            if (! $refund
                || $refund->source !== 'late_payment'
                || ! in_array($refund->status, self::ACTIVE_STATUSES, true)) {
                return false;
            }

            $checkedAt = now();
            $from = $refund->status;
            $requiresAttention = $refund->created_at
                ->copy()
                ->addHours($this->maxAgeHours())
                ->lte($checkedAt);

            $refund->update([
                'status' => $requiresAttention ? 'needs_attention' : $refund->status,
                'failure_message' => $requiresAttention
                    ? 'The automatic refund could not be confirmed with Paystack in time.'
                    : $refund->failure_message,
                'reconciliation_attempts' => $refund->reconciliation_attempts + 1,
                'last_reconciled_at' => $checkedAt,
                'next_reconciliation_at' => $requiresAttention
                    ? null
                    : $checkedAt->copy()->addMinutes($this->intervalMinutes()),
            ]);

            if ($requiresAttention) {
                $refund->events()->create([
                    'from_status' => $from,
                    'to_status' => 'needs_attention',
                    'action' => 'reconciliation_exhausted',
                    'actor_id' => null,
                    'note' => 'Automatic reconciliation could not confirm the refund with Paystack in time.',
                    'metadata' => ['attempts' => $refund->reconciliation_attempts],
                    'created_at' => $checkedAt,
                ]);
            }

            return $requiresAttention;
        }, 3);

        if ($requiresAttention) {
            $refund = Refund::query()->find($refundId);
            if ($refund) {
                $this->notifications->queueAttention($refund);
            }
        }
    }

    private function delayMinutes(): int
    {
        return (int) $this->settings->value('payments.refund_reconciliation_delay_minutes');
    }

    private function intervalMinutes(): int
    {
        return (int) $this->settings->value('payments.refund_reconciliation_interval_minutes');
    }

    private function maxAgeHours(): int
    {
        return (int) $this->settings->value('payments.refund_reconciliation_max_age_hours');
    }
}
