<?php

namespace App\Http\Requests\Referral;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReferralShareEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'channel' => ['required', Rule::in(['copy', 'email', 'whatsapp', 'facebook', 'x', 'sms', 'other'])],
            'url' => ['required', 'url:http,https', 'max:500'],
        ];
    }
}
