<?php

namespace App\Http\Requests\Support;

use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('contact_email')) {
            $this->merge(['contact_email' => mb_strtolower(trim((string) $this->input('contact_email')))]);
        }
    }

    public function rules(): array
    {
        $settings = app(BusinessSettingsService::class);
        $guest = $this->user('api') === null;

        return [
            'category' => ['required', 'string', Rule::in($settings->value('support.allowed_categories'))],
            'subject' => ['required', 'string', 'min:5', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
            'order_id' => ['nullable', 'integer'],
            'contact_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'max:160'],
            'contact_email' => [Rule::requiredIf($guest), 'nullable', 'email:rfc', 'max:255'],
            'attachments' => ['nullable', 'array', 'max:'.$settings->value('support.max_attachments')],
            'attachments.*' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf,txt,doc,docx',
                'max:'.$settings->value('media.document_max_size_kb'),
            ],
        ];
    }
}
