<?php

namespace App\Http\Requests\Admin\AccessControl;

use Illuminate\Contracts\Validation\ValidationRule;

class ViewUserRequest extends AccessControlRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
        ];
    }

    protected function requiredPermission(): string
    {
        return 'customers.read';
    }
}
