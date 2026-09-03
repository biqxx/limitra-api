<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cash = number_format($this->cash_balance_minor / 100, 2, '.', '');
        $limCash = number_format($this->lim_cash_balance_minor / 100, 2, '.', '');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'cash_balance' => $cash,
            'cash_balance_minor' => $this->cash_balance_minor,
            'lim_cash_balance' => $limCash,
            'lim_cash_balance_minor' => $this->lim_cash_balance_minor,
            'balance' => $cash,
            'bonus_balance' => $limCash,
            'currency' => $this->currency,
            'total_balance' => number_format(
                ($this->cash_balance_minor + $this->lim_cash_balance_minor) / 100,
                2,
                '.',
                '',
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
