<?php

namespace App\Services\Reward;

use App\Models\Reward\RewardCampaign;
use App\Models\Reward\RewardPrize;
use App\Models\Settings\BusinessSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardCampaignService
{
    public function create(array $data, User $actor): RewardCampaign
    {
        return DB::transaction(function () use ($data, $actor): RewardCampaign {
            $this->lockCampaignConfiguration();
            $startsAt = CarbonImmutable::parse($data['starts_at']);
            $endsAt = CarbonImmutable::parse($data['ends_at']);
            $this->assertNoActiveOverlap($data['status'], $startsAt, $endsAt);
            $campaign = RewardCampaign::query()->create([
                'public_id' => (string) Str::uuid(),
                'name' => $data['name'],
                'status' => $data['status'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'eligibility' => $data['eligibility'],
                'coupon_expiry_days' => $data['coupon_expiry_days'],
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->replacePrizes($campaign, $data['prizes']);

            return $campaign->load('prizes');
        }, 3);
    }

    public function update(RewardCampaign $campaign, array $data, User $actor): RewardCampaign
    {
        return DB::transaction(function () use ($campaign, $data, $actor): RewardCampaign {
            $this->lockCampaignConfiguration();
            $campaign = RewardCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $status = $data['status'] ?? $campaign->status;
            $startsAt = CarbonImmutable::parse($data['starts_at'] ?? $campaign->starts_at);
            $endsAt = CarbonImmutable::parse($data['ends_at'] ?? $campaign->ends_at);
            if ($startsAt->greaterThanOrEqualTo($endsAt)) {
                throw ValidationException::withMessages(['ends_at' => ['The campaign end must be after its start.']]);
            }
            $this->assertNoActiveOverlap($status, $startsAt, $endsAt, $campaign->id);

            $campaign->fill(array_intersect_key($data, array_flip([
                'name', 'status', 'starts_at', 'ends_at', 'eligibility', 'coupon_expiry_days',
            ])));
            $campaign->starts_at = $startsAt;
            $campaign->ends_at = $endsAt;
            $campaign->forceFill([
                'version' => $campaign->version + 1,
                'updated_by' => $actor->id,
            ])->save();

            if (isset($data['prizes'])) {
                $this->replacePrizes($campaign, $data['prizes']);
            }

            return $campaign->load('prizes');
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $prizes */
    private function replacePrizes(RewardCampaign $campaign, array $prizes): void
    {
        $weightTotal = collect($prizes)
            ->filter(fn (array $prize): bool => $prize['active'] ?? true)
            ->sum(fn (array $prize): int => $this->weightBasisPoints($prize['weight']));
        if ($weightTotal < 1 || $weightTotal > 1000000) {
            throw ValidationException::withMessages(['prizes' => ['Active prize weights must total between 0 and 100 percent.']]);
        }

        $retainedIds = [];
        foreach ($prizes as $index => $data) {
            $prize = isset($data['id'])
                ? RewardPrize::query()->where('reward_campaign_id', $campaign->id)->findOrFail($data['id'])
                : new RewardPrize(['reward_campaign_id' => $campaign->id, 'inventory_awarded' => 0]);
            $inventoryLimit = $data['inventory_limit'] ?? null;
            if ($inventoryLimit !== null && $inventoryLimit < $prize->inventory_awarded) {
                throw ValidationException::withMessages([
                    "prizes.{$index}.inventory_limit" => ['The inventory limit cannot be less than rewards already awarded.'],
                ]);
            }
            $prize->fill([
                'label' => $data['label'],
                'type' => $data['type'],
                'value_minor' => $this->toMinorUnits($data['value']),
                'weight_basis_points' => $this->weightBasisPoints($data['weight']),
                'inventory_limit' => $inventoryLimit,
                'active' => $data['active'] ?? true,
                'sort_order' => $index,
            ])->save();
            $retainedIds[] = $prize->id;
        }

        $campaign->prizes()->whereNotIn('id', $retainedIds)->update(['active' => false]);
    }

    private function assertNoActiveOverlap(
        string $status,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $exceptCampaignId = null,
    ): void {
        if ($status !== 'active') {
            return;
        }

        $overlap = RewardCampaign::query()
            ->where('status', 'active')
            ->when($exceptCampaignId, fn ($query) => $query->where('id', '!=', $exceptCampaignId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->lockForUpdate()
            ->first();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_at' => ['An active reward campaign already overlaps this period.']]);
        }
    }

    private function lockCampaignConfiguration(): void
    {
        BusinessSetting::query()
            ->where('key', 'rewards.enabled')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function weightBasisPoints(mixed $weight): int
    {
        return (int) round((float) $weight * 10000);
    }

    private function toMinorUnits(mixed $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }
}
