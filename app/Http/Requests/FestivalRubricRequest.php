<?php

namespace App\Http\Requests;

use App\Enums\FestivalRubricSectionContribution;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FestivalRubricRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $sections = collect($this->input('sections', []))
            ->map(function (mixed $section): mixed {
                if (! is_array($section)) {
                    return $section;
                }

                return ['contribution' => FestivalRubricSectionContribution::Award->value, ...$section];
            })
            ->all();

        $this->merge(['sections' => $sections]);
    }

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
            'sections.*.id' => ['nullable', 'integer', 'distinct'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.weight' => ['required', 'numeric', 'gt:0'],
            'sections.*.contribution' => ['required', Rule::enum(FestivalRubricSectionContribution::class)],
            'sections.*.criteria' => ['required', 'array', 'list', 'min:1'],
            'sections.*.criteria.*.id' => ['nullable', 'integer', 'distinct'],
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
                $this->validateStructureOwnership($validator);

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

            $this->validateStructureOwnership($validator);
        }];
    }

    private function validateStructureOwnership(Validator $validator): void
    {
        $rubric = $this->route('festivalRubric');

        if (! $rubric instanceof FestivalRubric) {
            return;
        }

        $sectionIds = collect($this->input('sections', []))->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id);
        $ownedSectionIds = $rubric->sections()->whereKey($sectionIds)->pluck('id');

        if ($ownedSectionIds->count() !== $sectionIds->unique()->count()) {
            $validator->errors()->add('sections', __('app.festival_rubric_structure_invalid'));

            return;
        }

        $criterionIds = collect($this->input('sections', []))
            ->flatMap(fn (mixed $section): array => is_array($section) ? ($section['criteria'] ?? []) : [])
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id);
        $ownedCriterionCount = FestivalRubricCriterion::query()
            ->whereIn('festival_rubric_section_id', $ownedSectionIds)
            ->whereKey($criterionIds)
            ->count();

        if ($ownedCriterionCount !== $criterionIds->unique()->count()) {
            $validator->errors()->add('sections', __('app.festival_rubric_structure_invalid'));
        }
    }
}
