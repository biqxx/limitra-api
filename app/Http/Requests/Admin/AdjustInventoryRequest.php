<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variant_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $this->route('product')?->id ?? $this->route('product')),
            ],
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => [
                'required',
                'string',
                Rule::in([
                    'restock',
                    'shrinkage',
                    'damage',
                    'audit_correction',
                    'return_restock',
                    'supplier_receipt',
                    'manual_adjustment',
                ]),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
