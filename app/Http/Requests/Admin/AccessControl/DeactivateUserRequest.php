<?php

namespace App\Http\Requests\Admin\AccessControl;

class DeactivateUserRequest extends AccessControlRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
