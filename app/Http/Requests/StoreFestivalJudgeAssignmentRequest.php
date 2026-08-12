<?php

namespace App\Http\Requests;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubricSection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class StoreFestivalJudgeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'min:1'],
            'festival_portal_user_id' => ['nullable', 'integer', 'min:1'],
            'display_name' => ['required', 'string', 'max:255'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct'],
            'section_ids' => ['sometimes', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct'],
            'is_head_judge' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');
            $edition = $this->route('festivalEdition');
            $userId = $this->integer('user_id') ?: null;
            $portalUserId = $this->integer('festival_portal_user_id') ?: null;

            if (($userId !== null ? 1 : 0) + ($portalUserId !== null ? 1 : 0) !== 1) {
                $validator->errors()->add('user_id', __('app.festival_judge_identity_required'));
            }

            if (! $account instanceof Account || ! $edition instanceof FestivalEdition || $edition->account_id !== $account->id) {
                return;
            }

            if ($userId !== null && ! $account->users()->whereKey($userId)->exists()) {
                $validator->errors()->add('user_id', __('app.festival_judge_staff_invalid'));
            }

            if ($userId !== null && FestivalJudgeAssignment::query()->where('festival_edition_id', $edition->id)->where('user_id', $userId)->exists()) {
                $validator->errors()->add('user_id', __('app.festival_judge_identity_duplicate'));
            }

            if ($portalUserId !== null && ! FestivalPortalUser::query()->whereBelongsTo($account)->forRole(FestivalPortalRole::Judge)->active()->whereKey($portalUserId)->exists()) {
                $validator->errors()->add('festival_portal_user_id', __('app.festival_judge_guest_invalid'));
            }

            if ($portalUserId !== null && FestivalJudgeAssignment::query()->where('festival_edition_id', $edition->id)->where('festival_portal_user_id', $portalUserId)->exists()) {
                $validator->errors()->add('festival_portal_user_id', __('app.festival_judge_identity_duplicate'));
            }

            $categoryIds = collect($this->input('category_ids', []))->map(fn (mixed $id): int => (int) $id)->unique()->values();
            $categoryCount = FestivalCategory::query()
                ->where('account_id', $account->id)
                ->where('festival_edition_id', $edition->id)
                ->whereKey($categoryIds)
                ->count();

            if ($categoryCount !== $categoryIds->count()) {
                $validator->errors()->add('category_ids', __('app.festival_judge_categories_invalid'));
            }

            $this->validateSections($validator, $account, $edition, $categoryIds);
        }];
    }

    /** @param Collection<int, int> $categoryIds */
    private function validateSections(Validator $validator, Account $account, FestivalEdition $edition, $categoryIds): void
    {
        if (! $this->has('section_ids')) {
            return;
        }

        $sectionIds = collect($this->input('section_ids', []))->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $sectionCount = FestivalRubricSection::query()
            ->where('account_id', $account->id)
            ->whereHas('rubric', fn ($query) => $query
                ->where('festival_edition_id', $edition->id)
                ->where('is_active', true)
                ->where(fn ($rubricQuery) => $rubricQuery->whereNull('festival_category_id')->orWhereIn('festival_category_id', $categoryIds)))
            ->whereKey($sectionIds)
            ->count();

        if ($sectionCount !== $sectionIds->count()) {
            $validator->errors()->add('section_ids', __('app.festival_judge_sections_invalid'));
        }
    }
}
