<?php

namespace App\Http\Requests\Admin\AccessControl;

class ListRoleMembersRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function requiredPermission(): string
    {
        return 'roles.read';
    }
}
