<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FestivalSeatHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'seat_ids' => ['present', 'array', 'list', 'max:20'],
            'seat_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
