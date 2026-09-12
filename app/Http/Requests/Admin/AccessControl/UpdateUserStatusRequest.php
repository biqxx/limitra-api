<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Enums\UserStatus;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => [
                Rule::requiredIf(fn (): bool => $this->input('status') === UserStatus::Suspended->value),
                'nullable', 'string', 'max:500',
            ],
            'suspended_until' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    protected function requiredPermission(): string
    {
        return 'customers.update';
    }
}
