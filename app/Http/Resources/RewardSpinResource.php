<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardSpinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cashPrize = $this->prize?->type === 'cash' ? $this->prize : null;

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'campaign' => $this->campaign ? [
                'id' => $this->campaign->public_id,
                'name' => $this->campaign->name,
            ] : null,
            'prize' => $this->prize ? [
                'id' => $this->prize->id,
                'label' => $this->prize->label,
                'type' => $this->prize->type,
                'value' => number_format($this->prize->value_minor / 100, 2, '.', ''),
                'value_minor' => $this->prize->value_minor,
            ] : null,
            'reward' => $cashPrize ? [
                'type' => 'lim_cash',
                'amount' => number_format($cashPrize->value_minor / 100, 2, '.', ''),
                'amount_minor' => $cashPrize->value_minor,
                'currency' => $this->walletTransaction?->currency ?? 'NGN',
                'credited' => $this->wallet_transaction_id !== null,
            ] : null,
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ]),
            'configuration_version' => $this->configuration_version,
            'rewarded_at' => $this->rewarded_at,
            'created_at' => $this->created_at,
        ];
    }
}
