<?php

namespace App\Http\Requests\AI;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAIConversationRequest extends FormRequest
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
            'channel' => ['sometimes', 'string', 'in:web'],
            'context' => ['sometimes', 'array:page,product_id,order_id,reasoning'],
            'context.page' => ['sometimes', 'string', 'max:255'],
            'context.product_id' => ['sometimes', 'integer', 'min:1'],
            'context.order_id' => ['sometimes', 'integer', 'min:1'],
            'context.reasoning' => ['sometimes', 'string', 'in:standard,high'],
        ];
    }
}
