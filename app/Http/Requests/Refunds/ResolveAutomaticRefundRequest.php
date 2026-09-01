<?php

namespace App\Http\Requests\Refunds;

use Illuminate\Foundation\Http\FormRequest;

class ResolveAutomaticRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:processed,failed'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
            'provider_reference' => ['nullable', 'required_if:status,processed', 'string', 'max:100'],
            'provider_refund_id' => ['nullable', 'string', 'max:100'],
            'processed_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
