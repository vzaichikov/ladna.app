<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FestivalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('festival') !== null;
    }

    public function rules(): array
    {
        return [
            'festival_category_id' => ['required', 'integer'],
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['required', 'integer', 'distinct'],
            'entry_name' => ['required', 'string', 'max:255'],
            'profile_phone' => ['nullable', 'string', 'max:50'],
            'profile_city' => ['nullable', 'string', 'max:255'],
            'profile_studio_name' => ['nullable', 'string', 'max:255'],
            'act_title' => ['nullable', 'string', 'max:255'],
            'act_description' => ['nullable', 'string', 'max:5000'],
            'comments' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
