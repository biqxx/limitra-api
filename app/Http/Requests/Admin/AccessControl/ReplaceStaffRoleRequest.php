<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Models\User\Role;
use Illuminate\Validation\Rule;

class ReplaceStaffRoleRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')],
        ];
    }
}
