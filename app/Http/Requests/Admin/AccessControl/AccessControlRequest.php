<?php

namespace App\Http\Requests\Admin\AccessControl;

use App\Models\User;
use App\Services\Auth\PermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

abstract class AccessControlRequest extends FormRequest
{
    public function authorize(PermissionResolver $permissions): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $permissions->allowsAll($user, [$this->requiredPermission()]);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge([
                'name' => Str::of($this->input('name'))->trim()->lower()->snake()->toString(),
            ]);
        }
    }

    protected function requiredPermission(): string
    {
        return 'roles.manage';
    }
}
