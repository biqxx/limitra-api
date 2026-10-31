<?php

namespace App\Http\Requests\Admin;

use App\Models\Product\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null && $this->user('api')->can('create', Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required_without:rows',
                'nullable',
                'file',
                'mimes:csv,txt,xlsx',
                'max:10240', // 10MB max
            ],
            'rows' => [
                'required_without:file',
                'nullable',
                'array',
                'max:5000',
            ],
            'dry_run' => ['nullable', 'boolean'],
            'duplicate_sku_policy' => ['nullable', 'string', Rule::in(['update', 'skip', 'error'])],
            'duplicate_slug_policy' => ['nullable', 'string', Rule::in(['generate_suffix', 'error'])],
        ];
    }
}
