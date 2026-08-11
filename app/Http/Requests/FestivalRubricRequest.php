<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'sections' => ['required', 'array', 'list', 'min:1'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.weight' => ['required', 'numeric', 'gt:0'],
            'sections.*.criteria' => ['required', 'array', 'list', 'min:1'],
            'sections.*.criteria.*.name' => ['required', 'string', 'max:255'],
            'sections.*.criteria.*.max_score' => ['required', 'numeric', 'gt:0'],
            'sections.*.criteria.*.weight' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('festival_category_id')) {
                return;
            }

            $account = $this->route('account');
            $edition = $this->route('festivalEdition');

            if (! $account instanceof Account || ! $edition instanceof FestivalEdition || $edition->account_id !== $account->id) {
                return;
            }

            $exists = FestivalCategory::query()
                ->where('account_id', $account->id)
                ->where('festival_edition_id', $edition->id)
                ->whereKey($this->integer('festival_category_id'))
                ->exists();

            if (! $exists) {
                $validator->errors()->add('festival_category_id', __('app.festival_rubric_category_invalid'));
            }
        }];
    }
}
