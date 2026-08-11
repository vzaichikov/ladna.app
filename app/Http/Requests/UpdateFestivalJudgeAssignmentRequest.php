<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateFestivalJudgeAssignmentRequest extends FormRequest
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
            'user_id' => ['prohibited'],
            'festival_portal_user_id' => ['prohibited'],
            'display_name' => ['required', 'string', 'max:255'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'distinct'],
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

            if (! $account instanceof Account || ! $edition instanceof FestivalEdition || $edition->account_id !== $account->id) {
                return;
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
        }];
    }
}
