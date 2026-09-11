<?php

namespace App\Http\Requests\Admin\AccessControl;

class RevokeStaffInvitationRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
