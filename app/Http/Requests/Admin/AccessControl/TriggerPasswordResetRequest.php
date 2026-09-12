<?php

namespace App\Http\Requests\Admin\AccessControl;

use Illuminate\Validation\Rule;

class TriggerPasswordResetRequest extends AccessControlRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'channel' => ['sometimes', 'string', Rule::in(['email'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function requiredPermission(): string
    {
        return 'customers.update';
    }
}
