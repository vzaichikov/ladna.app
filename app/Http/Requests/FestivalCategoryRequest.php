<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalWorkflow;
use App\Support\FestivalCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $edition = $this->route('festivalEdition');
        $category = $this->route('festivalCategory');

        if (! $edition instanceof FestivalEdition) {
            return;
        }

        $this->merge([
            'code' => $category instanceof FestivalCategory
                ? $category->code
                : FestivalCodeGenerator::unique(
                    (string) $this->input('name'),
                    'category',
                    fn (string $candidate): bool => $edition->categories()->where('code', $candidate)->exists(),
                ),
        ]);
    }

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
            'festival_direction_id' => [
                'required',
                'integer',
                Rule::exists((new FestivalDirection)->getTable(), 'id')
                    ->where('account_id', $edition instanceof FestivalEdition ? $edition->account_id : null)
                    ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)
                    ->where('is_active', true),
            ],
            'festival_workflow_id' => [
                'nullable',
                'integer',
                Rule::exists((new FestivalWorkflow)->getTable(), 'id')
                    ->where('account_id', $edition instanceof FestivalEdition ? $edition->account_id : null)
                    ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)
                    ->where('is_active', true),
            ],
            'min_members' => ['required', 'integer', 'min:1', 'max:100'],
            'max_members' => ['required', 'integer', 'gte:min_members', 'max:100'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_age' => ['nullable', 'integer', 'gte:min_age', 'max:100'],
            'min_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'max_duration_seconds' => ['nullable', 'integer', 'gte:min_duration_seconds'],
            'registration_closes_at' => ['nullable', 'date'],
            'requirements_html' => ['nullable', 'string', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
