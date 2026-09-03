<?php

namespace App\Http\Requests\Referral;

use Illuminate\Foundation\Http\FormRequest;

class StoreReferralInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'required_without:phone', 'prohibits:phone', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'prohibits:email', 'regex:/^\+[1-9]\d{7,14}$/'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
