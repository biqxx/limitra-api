<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignRoleMemberRequest extends AccessControlRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'nullable', 'integer', 'required_without:email',
                Rule::exists(User::class, 'id'),
            ],
            'name' => ['required_without:user_id', 'string', 'max:120'],
            'email' => ['required_without:user_id', 'string', 'email', 'max:255'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('user_id') && ($this->filled('name') || $this->filled('email'))) {
                    $validator->errors()->add('user_id', 'Choose either an existing user or an invitation.');
                    $validator->errors()->add('email', 'Choose either an existing user or an invitation.');
                }
            },
        ];
    }
}
