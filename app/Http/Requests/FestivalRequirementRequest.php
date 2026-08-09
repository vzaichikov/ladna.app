<?php

namespace App\Http\Requests;

use App\Enums\FestivalRequirementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'festival_category_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::enum(FestivalRequirementType::class)],
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'stage' => ['required', Rule::in(['qualification', 'final'])],
            'due_at' => ['nullable', 'date'],
            'allowed_extensions' => ['sometimes', 'array'],
            'allowed_extensions.*' => ['string', 'max:20', 'regex:/^[a-zA-Z0-9]+$/'],
            'allowed_mime_types' => ['sometimes', 'array'],
            'allowed_mime_types.*' => ['string', 'max:150'],
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:102400'],
            'min_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'max_duration_seconds' => ['nullable', 'integer', 'gte:min_duration_seconds'],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }
}
