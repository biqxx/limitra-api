<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Enums\UserStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ListUsersRequest extends AccessControlRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::enum(UserStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => [
                'sometimes',
                'date_format:Y-m-d',
                Rule::when($this->filled('from'), ['after_or_equal:from']),
            ],
            'sort' => ['sometimes', Rule::in(['created_at', 'updated_at', 'username', 'email', 'role', 'status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];

        if ($this->has('q') && is_string($this->input('q'))) {
            $normalized['q'] = trim($this->input('q'));
        }

        if ($this->has('role') && is_string($this->input('role'))) {
            $normalized['role'] = Str::of($this->input('role'))->trim()->lower()->snake()->toString();
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    protected function requiredPermission(): string
    {
        return 'customers.read';
    }
}
