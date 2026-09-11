<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Models\User\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreStaffInvitationRequest extends AccessControlRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Role::class, 'id')],
        ];
    }
}
