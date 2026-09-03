<?php

namespace App\Http\Requests\Reward;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRewardCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'status' => ['required', Rule::in(['draft', 'active', 'paused', 'ended'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'eligibility' => ['required', 'array'],
            'eligibility.new_accounts_only' => ['required', 'boolean'],
            'eligibility.max_claims_per_user' => ['required', 'integer', 'min:1', 'max:100'],
            'coupon_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'prizes' => ['required', 'array', 'min:1', 'max:50'],
            'prizes.*.label' => ['required', 'string', 'max:160'],
            'prizes.*.type' => ['required', Rule::in(['cash', 'no_reward'])],
            'prizes.*.value' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'prizes.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'prizes.*.inventory_limit' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'prizes.*.active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $prizes = collect($this->input('prizes', []))
                ->filter(fn (mixed $prize): bool => is_array($prize) && ($prize['active'] ?? true));
            if ($prizes->sum('weight') > 100) {
                $validator->errors()->add('prizes', 'The active prize weights cannot exceed 100 percent.');
            }
            foreach ($prizes as $index => $prize) {
                if (($prize['type'] ?? null) === 'cash' && (float) ($prize['value'] ?? 0) <= 0) {
                    $validator->errors()->add("prizes.{$index}.value", 'Cash prizes must have a positive value.');
                }
                if (($prize['type'] ?? null) === 'no_reward' && (float) ($prize['value'] ?? 0) !== 0.0) {
                    $validator->errors()->add("prizes.{$index}.value", 'No-reward prizes must have a zero value.');
                }
            }
        }];
    }
}
