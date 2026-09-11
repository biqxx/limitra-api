<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Models\User\Permission;
use App\Models\User\Role;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends AccessControlRequest
{
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique(Role::class, 'name')->ignore($role)],
            'display_name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permissions' => ['sometimes', 'array', 'max:100'],
            'permissions.*' => ['required', 'string', 'distinct:strict', Rule::exists(Permission::class, 'name')],
            'is_system' => ['prohibited'],
            'is_super_admin' => ['prohibited'],
        ];
    }

    protected function normalizesRoleName(): bool
    {
        return true;
    }
}
