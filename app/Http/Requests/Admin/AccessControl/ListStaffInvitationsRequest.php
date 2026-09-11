<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Enums\StaffInvitationStatus;
use Illuminate\Validation\Rule;

class ListStaffInvitationsRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'string', Rule::enum(StaffInvitationStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function requiredPermission(): string
    {
        return 'roles.read';
    }
}
