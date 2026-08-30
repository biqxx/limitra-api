<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'body' => ['sometimes', 'string', 'min:3', 'max:5000'],
        ];
    }
}
