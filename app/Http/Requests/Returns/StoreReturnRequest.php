<?php

namespace App\Http\Requests\Returns;

use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $settings = app(BusinessSettingsService::class);

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.reason' => ['required', Rule::in(['damaged', 'defective', 'wrong_item', 'not_as_described', 'missing_parts', 'other'])],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'resolution' => ['required', Rule::in($settings->value('returns.allowed_resolutions'))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:'.$settings->value('returns.max_images')],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$settings->value('media.image_max_size_kb')],
        ];
    }
}
