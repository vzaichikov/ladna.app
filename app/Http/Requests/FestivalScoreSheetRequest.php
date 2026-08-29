<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FestivalScoreSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comments' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'scores' => ['sometimes', 'array'],
            'scores.*.criterion_id' => ['required', 'integer', 'distinct'],
            'scores.*.score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'scores.*.comment' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];
    }
}
