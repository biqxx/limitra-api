<?php

namespace App\Http\Requests\Reward;

class UpdateRewardCampaignRequest extends StoreRewardCampaignRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['name', 'status', 'starts_at', 'ends_at', 'eligibility', 'coupon_expiry_days', 'prizes'] as $field) {
            $rules[$field] = array_map(
                fn (mixed $rule): mixed => $rule === 'required' ? 'sometimes' : $rule,
                $rules[$field],
            );
        }

        $rules['ends_at'] = array_values(array_filter(
            $rules['ends_at'],
            fn (mixed $rule): bool => $rule !== 'after:starts_at',
        ));
        $rules['eligibility.new_accounts_only'][0] = 'required_with:eligibility';
        $rules['eligibility.max_claims_per_user'][0] = 'required_with:eligibility';
        $rules['prizes.*.id'] = ['sometimes', 'integer', 'distinct'];

        return $rules;
    }
}
