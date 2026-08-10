<?php

namespace App\Http\Requests;

use App\Enums\FestivalCategoryWorkflow;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        $edition = $this->route('festivalEdition');
        $category = $this->route('festivalCategory');

        return [
            'code' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('festival_categories')->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)->ignore($category instanceof FestivalCategory ? $category->id : null)],
            'name' => ['required', 'string', 'max:255'],
            'festival_workflow_id' => ['nullable', 'integer'],
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
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
