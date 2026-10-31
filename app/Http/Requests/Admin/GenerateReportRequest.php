<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateReportRequest extends FormRequest
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
            'type' => [
                'required',
                'string',
                Rule::in([
                    'orders',
                    'customers',
                    'products',
                    'inventory',
                    'affiliates',
                    'commissions',
                    'withdrawals',
                    'revenue',
                    'traffic',
                    'conversion',
                ]),
            ],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx'])],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', 'max:60'],
            'filters' => ['nullable', 'array'],
            'filters.status' => ['nullable', 'string', 'max:50'],
            'filters.payment_status' => ['nullable', 'string', 'max:50'],
            'filters.category_id' => ['nullable', 'integer'],
            'filters.low_stock' => ['nullable', 'boolean'],
            'filters.affiliate_id' => ['nullable', 'integer'],
        ];
    }
}
