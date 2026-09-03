<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexWalletTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in([
                'deposit',
                'withdrawal',
                'referral_reward',
                'spin_reward',
                'purchase',
                'refund',
                'adjustment',
                'reversal',
            ])],
            'balance_type' => ['sometimes', Rule::in(['cash', 'lim_cash'])],
            'status' => ['sometimes', Rule::in(['posted'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
