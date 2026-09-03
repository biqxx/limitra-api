<?php

namespace App\Http\Requests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['open', 'pending', 'waiting_on_customer', 'resolved', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assignee_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn (Builder $query): Builder => $query->whereIn('role', ['admin', 'staff']),
                ),
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(['status', 'priority', 'assignee_id'])) {
                $validator->errors()->add('ticket', 'At least one ticket field is required.');
            }
        }];
    }
}
