<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Models\User;
use Illuminate\Validation\Rule;

class AssignRoleMemberRequest extends AccessControlRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists(User::class, 'id')],
        ];
    }
}
