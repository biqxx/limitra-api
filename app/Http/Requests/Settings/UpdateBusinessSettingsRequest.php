<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Services\Auth\PermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(PermissionResolver $permissions): bool
    {
        $user = $this->user();

        return $user instanceof User && $permissions->allowsAll($user, ['settings.manage']);
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
