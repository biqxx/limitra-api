<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Models\User\Permission;
use App\Models\User\Role;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique(Role::class, 'name')],
            'display_name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['present', 'array', 'max:100'],
            'permissions.*' => ['required', 'string', 'distinct:strict', Rule::exists(Permission::class, 'name')],
            'is_system' => ['prohibited'],
            'is_super_admin' => ['prohibited'],
        ];
    }
}
