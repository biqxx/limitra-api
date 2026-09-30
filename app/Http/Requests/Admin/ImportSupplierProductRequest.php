<?php

namespace App\Http\Requests\Admin;

use App\Models\Product\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ImportSupplierProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(['amazon', 'ebay'])],
            'external_product_id' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255', 'unique:products,sku'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('subcategory_id') || ! $this->filled('category_id')) {
                return;
            }

            $belongsToCategory = Category::query()
                ->whereKey($this->integer('subcategory_id'))
                ->where('parent_id', $this->integer('category_id'))
                ->exists();

            if (! $belongsToCategory) {
                $validator->errors()->add('subcategory_id', 'The selected subcategory does not belong to the category.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['source' => strtolower((string) $this->input('source'))]);
    }
}
