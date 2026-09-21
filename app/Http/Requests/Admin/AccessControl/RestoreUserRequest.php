<?php

namespace App\Http\Requests\Admin\AccessControl;

class RestoreUserRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    protected function requiredPermission(): string
    {
        return 'customers.update';
    }
}
