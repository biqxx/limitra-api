<?php

namespace App\Http\Requests\Admin\AccessControl;

use Illuminate\Validation\Rule;

class UpdateLegacyRoleRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(['user', 'affiliate', 'staff', 'admin'])],
        ];
    }
}
