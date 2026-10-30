<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPreferenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'theme' => ['sometimes', 'string', Rule::in(['light', 'dark', 'system'])],
            'locale' => ['sometimes', 'string', 'max:10'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notifications_enabled' => ['sometimes', 'boolean'],
            'email_notifications' => ['sometimes', 'boolean'],
            'sms_notifications' => ['sometimes', 'boolean'],
            'push_notifications' => ['sometimes', 'boolean'],
            'in_app_notifications' => ['sometimes', 'boolean'],
            'marketing_emails' => ['sometimes', 'boolean'],
            'marketing_sms' => ['sometimes', 'boolean'],
            'marketing_push' => ['sometimes', 'boolean'],
            'marketing_in_app' => ['sometimes', 'boolean'],
        ];
    }
}
