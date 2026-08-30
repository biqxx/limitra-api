<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class ModerateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:pending,published,rejected'],
            'reason' => ['nullable', 'required_if:status,rejected', 'string', 'max:1000'],
        ];
    }
}
