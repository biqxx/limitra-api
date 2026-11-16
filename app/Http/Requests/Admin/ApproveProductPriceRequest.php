<?php

namespace App\Http\Requests\Admin;

use App\Models\Product\ProductSource;
use App\Models\User;
use App\Services\Auth\PermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveProductPriceRequest extends FormRequest
{
    public function authorize(PermissionResolver $permissions): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $permissions->allowsAll($user, ['catalog.sourcing']);
    }

    public function rules(): array
    {
        $productSource = $this->route('productSource');
        $productSourceId = $productSource instanceof ProductSource
            ? $productSource->id
            : $productSource;

        return [
            'quote_id' => [
                'sometimes',
                'integer',
                Rule::exists('product_price_quotes', 'id')->where('product_source_id', $productSourceId),
            ],
        ];
    }
}
