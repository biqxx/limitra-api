<?php

namespace App\Http\Requests\Support;

use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupportTicketMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $settings = app(BusinessSettingsService::class);

        return [
            'message' => ['nullable', 'required_without:attachments', 'string', 'min:1', 'max:10000'],
            'attachments' => [
                'nullable',
                'required_without:message',
                'array',
                'max:'.$settings->value('support.max_attachments'),
            ],
            'attachments.*' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf,txt,doc,docx',
                'max:'.$settings->value('media.document_max_size_kb'),
            ],
        ];
    }
}
