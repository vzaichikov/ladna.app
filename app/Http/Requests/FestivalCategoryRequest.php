<?php

namespace App\Http\Requests;

use App\Enums\FestivalCategoryWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'alpha_dash:ascii', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'workflow' => ['required', Rule::enum(FestivalCategoryWorkflow::class)],
            'min_members' => ['required', 'integer', 'min:1', 'max:100'],
            'max_members' => ['required', 'integer', 'gte:min_members', 'max:100'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_age' => ['nullable', 'integer', 'gte:min_age', 'max:100'],
            'min_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'max_duration_seconds' => ['nullable', 'integer', 'gte:min_duration_seconds'],
            'registration_closes_at' => ['nullable', 'date'],
            'option_ids' => ['sometimes', 'array'],
            'option_ids.*' => ['integer', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
