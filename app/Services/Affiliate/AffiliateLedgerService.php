<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateLedgerEntry;
use App\Models\Affiliate\AffiliateWithdrawal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AffiliateLedgerService
{
    /**
     * Calculate authoritative available balance using ledger records or fallback.
     */
    public function calculateAuthoritativeAvailableBalance(Affiliate $affiliate, ?int $excludeWithdrawalId = null): float
    {
        $ledgerEarnings = (float) $affiliate->ledgerEntries()->where('type', 'earning')->sum('amount');
        $baseEarnings = max((float) $affiliate->total_earnings, $ledgerEarnings);

        $hasLedgerDebits = $affiliate->ledgerEntries()->where('type', '!=', 'earning')->exists();

        if ($hasLedgerDebits) {
            $ledgerNonEarnings = (float) $affiliate->ledgerEntries()->where('type', '!=', 'earning')->sum('amount');

            return round(max(0.0, $baseEarnings + $ledgerNonEarnings), 2);
        }

        $heldQuery = $affiliate->withdrawals()->whereIn('status', ['pending', 'approved', 'processing']);
        if ($excludeWithdrawalId) {
            $heldQuery->where('id', '!=', $excludeWithdrawalId);
        }
        $held = (float) $heldQuery->sum('amount');
        $paid = (float) $affiliate->total_paid;

        return round(max(0.0, $baseEarnings - $held - $paid), 2);
    }

    /**
     * Record an affiliate earning in the ledger.
     */
    public function recordEarning(
        Affiliate $affiliate,
        float $amount,
        string $referenceType,
        int $referenceId,
        string $description,
        ?string $idempotencyKey = null,
        array $metadata = []
    ): AffiliateLedgerEntry {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Earning amount must be greater than zero.');
        }

        return DB::transaction(function () use ($affiliate, $amount, $referenceType, $referenceId, $description, $idempotencyKey, $metadata): AffiliateLedgerEntry {
            if ($idempotencyKey) {
                $existing = AffiliateLedgerEntry::where('affiliate_id', $affiliate->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $currentAvailable = $this->calculateAuthoritativeAvailableBalance($lockedAffiliate);
            $newBalance = round($currentAvailable + $amount, 2);

            $entry = AffiliateLedgerEntry::create([
                'affiliate_id' => $lockedAffiliate->id,
                'type' => 'earning',
                'amount' => $amount,
                'currency' => 'NGN',
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $lockedAffiliate->total_earnings = round((float) $lockedAffiliate->total_earnings + $amount, 2);
            $lockedAffiliate->save();

            return $entry;
        });
    }

    /**
     * Reserve funds by placing a balance hold when a withdrawal is requested.
     */
    public function recordHold(
        Affiliate $affiliate,
        float $amount,
        AffiliateWithdrawal $withdrawal,
        ?string $idempotencyKey = null
    ): AffiliateLedgerEntry {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Hold amount must be greater than zero.');
        }

        return DB::transaction(function () use ($affiliate, $amount, $withdrawal, $idempotencyKey): AffiliateLedgerEntry {
            if ($idempotencyKey) {
                $existing = AffiliateLedgerEntry::where('affiliate_id', $affiliate->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $currentAvailable = $this->calculateAuthoritativeAvailableBalance($lockedAffiliate, $withdrawal->id);

            if ($amount > $currentAvailable) {
                throw new InvalidArgumentException("Insufficient available balance ({$currentAvailable}) for hold ({$amount}).");
            }

            $newBalance = round($currentAvailable - $amount, 2);

            return AffiliateLedgerEntry::create([
                'affiliate_id' => $lockedAffiliate->id,
                'type' => 'hold',
                'amount' => -$amount, // Negative debit reserves available balance
                'currency' => $withdrawal->currency ?? 'NGN',
                'balance_after' => $newBalance,
                'reference_type' => AffiliateWithdrawal::class,
                'reference_id' => $withdrawal->id,
                'idempotency_key' => $idempotencyKey,
                'description' => "Hold for withdrawal reference {$withdrawal->reference}",
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'reference' => $withdrawal->reference,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Release previously held funds back to available balance when withdrawal is rejected/failed.
     */
    public function recordRelease(
        Affiliate $affiliate,
        float $amount,
        AffiliateWithdrawal $withdrawal,
        string $reason
    ): AffiliateLedgerEntry {
        return DB::transaction(function () use ($affiliate, $amount, $withdrawal, $reason): AffiliateLedgerEntry {
            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $currentAvailable = $this->calculateAuthoritativeAvailableBalance($lockedAffiliate);
            $newBalance = round($currentAvailable + $amount, 2);

            return AffiliateLedgerEntry::create([
                'affiliate_id' => $lockedAffiliate->id,
                'type' => 'release',
                'amount' => $amount, // Positive credit restores held balance
                'currency' => $withdrawal->currency ?? 'NGN',
                'balance_after' => $newBalance,
                'reference_type' => AffiliateWithdrawal::class,
                'reference_id' => $withdrawal->id,
                'idempotency_key' => "release-{$withdrawal->id}-{$withdrawal->updated_at?->timestamp}",
                'description' => "Release hold for rejected withdrawal {$withdrawal->reference}: {$reason}",
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'reference' => $withdrawal->reference,
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Record final settlement when a withdrawal is marked paid.
     */
    public function recordSettlement(
        Affiliate $affiliate,
        float $amount,
        AffiliateWithdrawal $withdrawal,
        string $providerReference
    ): AffiliateLedgerEntry {
        return DB::transaction(function () use ($affiliate, $amount, $withdrawal, $providerReference): AffiliateLedgerEntry {
            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $currentAvailable = $this->calculateAuthoritativeAvailableBalance($lockedAffiliate);

            return AffiliateLedgerEntry::create([
                'affiliate_id' => $lockedAffiliate->id,
                'type' => 'settlement',
                'amount' => 0.00, // Balance already deducted by hold
                'currency' => $withdrawal->currency ?? 'NGN',
                'balance_after' => $currentAvailable,
                'reference_type' => AffiliateWithdrawal::class,
                'reference_id' => $withdrawal->id,
                'idempotency_key' => "settlement-{$withdrawal->id}-{$providerReference}",
                'description' => "Settlement completed for withdrawal {$withdrawal->reference} via {$providerReference}",
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'reference' => $withdrawal->reference,
                    'provider_reference' => $providerReference,
                    'amount' => $amount,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Reverse a commission when an order is cancelled or refunded.
     */
    public function recordReversal(
        Affiliate $affiliate,
        float $amount,
        string $referenceType,
        int $referenceId,
        string $reason
    ): AffiliateLedgerEntry {
        return DB::transaction(function () use ($affiliate, $amount, $referenceType, $referenceId, $reason): AffiliateLedgerEntry {
            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $currentAvailable = $this->calculateAuthoritativeAvailableBalance($lockedAffiliate);
            $newBalance = round(max(0.0, $currentAvailable - $amount), 2);

            $entry = AffiliateLedgerEntry::create([
                'affiliate_id' => $lockedAffiliate->id,
                'type' => 'reversal',
                'amount' => -$amount,
                'currency' => 'NGN',
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => "reversal-{$referenceType}-{$referenceId}",
                'description' => "Commission reversal: {$reason}",
                'metadata' => [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);

            $lockedAffiliate->total_earnings = round(max(0.0, (float) $lockedAffiliate->total_earnings - $amount), 2);
            $lockedAffiliate->save();

            return $entry;
        });
    }
}
