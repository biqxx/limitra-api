<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptStaffInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'username' => [
                'required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique(User::class, 'username'),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
