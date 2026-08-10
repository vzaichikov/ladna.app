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
            'comments' => ['nullable', 'string', 'max:5000'],
            'scores' => ['required', 'array'],
            'scores.*.criterion_id' => ['required', 'integer', 'distinct'],
            'scores.*.score' => ['required', 'numeric', 'min:0'],
            'scores.*.comment' => ['nullable', 'string', 'max:3000'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }
}
