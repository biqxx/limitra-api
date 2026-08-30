<?php

namespace App\Http\Requests\Returns;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,rejected,received,completed'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:2000'],
            'items' => ['nullable', 'required_if:status,approved', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct', 'exists:return_items,id'],
            'items.*.approved_quantity' => ['required', 'integer', 'min:0'],
        ];
    }
}
