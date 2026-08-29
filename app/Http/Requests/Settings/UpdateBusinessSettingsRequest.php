<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1', 'max:50'],
            'settings.*.key' => ['required', 'string', 'distinct'],
            'settings.*.value' => ['present'],
        ];
    }
}
