<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;

class FestivalRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        return [
            'festival_category_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.weight' => ['required', 'numeric', 'gt:0'],
            'sections.*.criteria' => ['required', 'array', 'min:1'],
            'sections.*.criteria.*.name' => ['required', 'string', 'max:255'],
            'sections.*.criteria.*.max_score' => ['required', 'numeric', 'gt:0'],
            'sections.*.criteria.*.weight' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
