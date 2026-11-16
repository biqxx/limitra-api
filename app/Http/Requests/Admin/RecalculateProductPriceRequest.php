<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\Auth\PermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

class RecalculateProductPriceRequest extends FormRequest
{
    public function authorize(PermissionResolver $permissions): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $permissions->allowsAll($user, ['catalog.sourcing']);
    }

    public function rules(): array
    {
        return [
            'supplier_price' => ['sometimes', 'numeric', 'gt:0'],
            'supplier_currency' => ['sometimes', 'string', 'size:3'],
            'weight_lb' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'delivery_days_min' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'delivery_days_max' => ['sometimes', 'integer', 'min:1', 'max:365', 'gte:delivery_days_min'],
        ];
    }
}
