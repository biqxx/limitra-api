<?php

namespace App\Http\Requests\Admin\AccessControl;

class SuspendUserRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'suspended_until' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    protected function requiredPermission(): string
    {
        return 'customers.update';
    }
}
