<?php

namespace App\Http\Requests\AI;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAIConversationMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:4000'],
            'client_message_id' => ['required', 'string', 'min:8', 'max:100'],
            'images' => ['sometimes', 'array', 'max:3'],
            'images.*' => ['image', 'mimes:jpeg,png,webp,jpg', 'max:5120'],
        ];
    }
}
