<?php

namespace App\Http\Requests\Support;

use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminSupportTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categories = app(BusinessSettingsService::class)->value('support.allowed_categories');

        return [
            'queue' => ['sometimes', 'string', Rule::in($categories)],
            'status' => ['sometimes', Rule::in(['open', 'pending', 'waiting_on_customer', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assignee_id' => ['sometimes', 'integer', 'exists:users,id'],
            'unassigned' => ['sometimes', 'boolean'],
            'sla' => ['sometimes', Rule::in(['overdue', 'due_soon'])],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
