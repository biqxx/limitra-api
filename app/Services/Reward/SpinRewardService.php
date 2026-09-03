<?php

namespace App\Services\Reward;

use App\Models\Reward\RewardCampaign;
use App\Models\Reward\RewardPrize;
use App\Models\Reward\RewardSpin;
use App\Models\User;
use App\Services\Payment\WalletLedgerService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpinRewardService
{
    private const MAXIMUM_ROLL = 1000000;

    public function __construct(
        private readonly BusinessSettingsService $settings,
        private readonly WalletLedgerService $walletLedger,
    ) {}

    /** @return array{spin: RewardSpin, replayed: bool} */
    public function spin(User $user, string $idempotencyKey): array
    {
        $existing = RewardSpin::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return ['spin' => $existing->load(['campaign', 'prize', 'walletTransaction']), 'replayed' => true];
        }

        return DB::transaction(function () use ($user, $idempotencyKey): array {
            $existing = RewardSpin::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return ['spin' => $existing->load(['campaign', 'prize', 'walletTransaction']), 'replayed' => true];
            }
            if (! $this->settings->value('rewards.enabled')) {
                $this->invalid('rewards', 'Rewards are currently disabled.');
            }

            $campaign = RewardCampaign::query()
                ->where('status', 'active')
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->latest('starts_at')
                ->lockForUpdate()
                ->first();
            if (! $campaign) {
                $this->invalid('campaign', 'There is no active Spin & Win campaign.');
            }
            $this->assertEligible($user, $campaign);

            $prizes = $campaign->prizes()->where('active', true)->lockForUpdate()->get();
            $totalWeight = (int) $prizes->sum('weight_basis_points');
            if ($totalWeight < 1 || $totalWeight > self::MAXIMUM_ROLL) {
                $this->invalid('campaign', 'The active reward campaign is not configured correctly.');
            }

            $roll = random_int(1, self::MAXIMUM_ROLL);
            $prize = $this->selectPrize($prizes, $roll);
            if ($prize && $prize->inventory_limit !== null && $prize->inventory_awarded >= $prize->inventory_limit) {
                $prize = null;
            }
            if ($prize) {
                $prize->increment('inventory_awarded');
            }

            $isCashWin = $prize?->type === 'cash' && $prize->value_minor > 0;
            $spin = RewardSpin::query()->create([
                'public_id' => (string) Str::uuid(),
                'reward_campaign_id' => $campaign->id,
                'reward_prize_id' => $prize?->id,
                'user_id' => $user->id,
                'status' => $isCashWin ? 'won' : 'no_reward',
                'idempotency_key' => $idempotencyKey,
                'selection_roll' => $roll,
                'total_weight_basis_points' => $totalWeight,
                'configuration_version' => $campaign->version,
                'configuration_snapshot' => $this->snapshot($campaign, $prizes, $prize),
            ]);

            if ($isCashWin) {
                $transaction = $this->walletLedger->credit(
                    $user,
                    $prize->value_minor,
                    'lim_cash',
                    'spin_reward',
                    "spin_reward:{$spin->id}",
                    "Spin & Win reward from {$campaign->name}.",
                    'reward_spin',
                    $spin->id,
                    ['campaign_id' => $campaign->id, 'prize_id' => $prize->id],
                );
                $spin->update(['wallet_transaction_id' => $transaction->id, 'rewarded_at' => now()]);
            }

            return ['spin' => $spin->fresh()->load(['campaign', 'prize', 'walletTransaction']), 'replayed' => false];
        }, 3);
    }

    public function activeCampaign(?User $user = null): ?RewardCampaign
    {
        $campaign = RewardCampaign::query()
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->latest('starts_at')
            ->with(['prizes' => fn ($query) => $query->where('active', true)])
            ->first();

        if ($campaign && $user) {
            $campaign->setAttribute('can_spin', $this->isEligible($user, $campaign));
        }

        return $campaign;
    }

    /** @param Collection<int, RewardPrize> $prizes */
    private function selectPrize(Collection $prizes, int $roll): ?RewardPrize
    {
        $ceiling = 0;
        foreach ($prizes as $prize) {
            $ceiling += $prize->weight_basis_points;
            if ($roll <= $ceiling) {
                return $prize;
            }
        }

        return null;
    }

    private function assertEligible(User $user, RewardCampaign $campaign): void
    {
        if (! $this->isEligible($user, $campaign)) {
            $this->invalid('eligibility', 'This account is not eligible for another spin in this campaign.');
        }
    }

    private function isEligible(User $user, RewardCampaign $campaign): bool
    {
        $eligibility = $campaign->eligibility;
        if (($eligibility['new_accounts_only'] ?? false) && $user->created_at->lt($campaign->starts_at)) {
            return false;
        }

        return RewardSpin::query()
            ->where('reward_campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->count() < (int) ($eligibility['max_claims_per_user'] ?? 1);
    }

    /** @param Collection<int, RewardPrize> $prizes */
    private function snapshot(RewardCampaign $campaign, Collection $prizes, ?RewardPrize $selected): array
    {
        return [
            'campaign' => ['id' => $campaign->id, 'name' => $campaign->name, 'version' => $campaign->version],
            'eligibility' => $campaign->eligibility,
            'prizes' => $prizes->map(fn (RewardPrize $prize): array => [
                'id' => $prize->id,
                'type' => $prize->type,
                'value_minor' => $prize->value_minor,
                'weight_basis_points' => $prize->weight_basis_points,
                'inventory_limit' => $prize->inventory_limit,
            ])->values()->all(),
            'selected_prize_id' => $selected?->id,
        ];
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
