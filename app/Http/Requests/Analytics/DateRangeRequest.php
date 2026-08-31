<?php

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

class DateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') || $this->user()?->hasRole('staff');
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date', 'before_or_equal:to'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'group_by' => ['sometimes', 'in:hourly,daily,monthly'],
        ];
    }

    public function from(): string
    {
        return $this->input('from', now()->subDays(29)->toDateString());
    }

    public function to(): string
    {
        return $this->input('to', now()->toDateString());
    }

    public function groupBy(): string
    {
        return $this->input('group_by', 'daily');
    }
}
