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

        $normalized = [];

        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim($this->input('email')));
        }

        if (is_string($this->input('role'))) {
            $normalized['role'] = Str::of($this->input('role'))->trim()->lower()->snake()->toString();
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['sometimes', 'nullable', 'integer', 'prohibits:role', Rule::exists(Role::class, 'id')],
            'role' => ['sometimes', 'nullable', 'string', 'max:100', 'prohibits:role_id', Rule::exists(Role::class, 'name')],
            'invite' => ['sometimes', 'accepted'],
            'username' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}
