<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueueSupplierProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'products' => ['required', 'array', 'min:1', 'max:20'],
            'products.*.source' => ['required', Rule::in(['amazon', 'ebay'])],
            'products.*.external_product_id' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $products = collect($this->input('products', []))
            ->map(function (mixed $product): mixed {
                if (! is_array($product)) {
                    return $product;
                }

                $product['source'] = strtolower((string) ($product['source'] ?? ''));

                return $product;
            })
            ->all();

        $this->merge(['products' => $products]);
    }
}
