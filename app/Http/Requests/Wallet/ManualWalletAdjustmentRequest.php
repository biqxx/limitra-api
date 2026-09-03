<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class ManualWalletAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'balance_type' => ['sometimes', 'in:cash,lim_cash'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
