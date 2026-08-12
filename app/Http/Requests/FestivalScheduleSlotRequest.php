<?php

namespace App\Http\Requests;

use App\Enums\FestivalScheduleSlotType;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FestivalScheduleSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalSchedule', $account);
    }

    public function rules(): array
    {
        $edition = $this->route('festivalEdition');
        $editionId = $edition instanceof FestivalEdition ? $edition->id : null;
        $accountId = $edition instanceof FestivalEdition ? $edition->account_id : null;
        $stageId = $this->integer('festival_stage_id');
        $type = FestivalScheduleSlotType::tryFrom((string) $this->input('type'));

        return [
            'festival_stage_id' => [
                'required',
                'integer',
                Rule::exists((new FestivalStage)->getTable(), 'id')
                    ->where('festival_edition_id', $editionId)
                    ->where('account_id', $accountId),
            ],
            'festival_entry_id' => [
                Rule::requiredIf($type?->requiresEntry() ?? false),
                'nullable',
                'integer',
                Rule::exists((new FestivalEntry)->getTable(), 'id')
                    ->where('festival_edition_id', $editionId)
                    ->where('account_id', $accountId),
            ],
            'festival_category_id' => [
                Rule::requiredIf($type === FestivalScheduleSlotType::CategoryHeader),
                'nullable',
                'integer',
                Rule::exists((new FestivalCategory)->getTable(), 'id')
                    ->where('festival_edition_id', $editionId)
                    ->where('account_id', $accountId),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists((new FestivalScheduleSlot)->getTable(), 'id')
                    ->where('festival_edition_id', $editionId)
                    ->where('festival_stage_id', $stageId)
                    ->where('account_id', $accountId),
            ],
            'type' => ['required', Rule::enum(FestivalScheduleSlotType::class)],
            'name' => [
                Rule::requiredIf(in_array($type, [FestivalScheduleSlotType::Custom, FestivalScheduleSlotType::FreeHeader], true)),
                'nullable',
                'string',
                'max:255',
            ],
            'starts_at' => [Rule::requiredIf($type?->isTimed() ?? false), 'nullable', 'date'],
            'ends_at' => [Rule::requiredIf($type?->isTimed() ?? false), 'nullable', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'reschedule_reason' => ['nullable', 'string', 'max:3000'],
            'is_published' => ['sometimes', 'boolean'],
            'editing_item_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = FestivalScheduleSlotType::from((string) $this->input('type'));
            $slot = $this->route('festivalScheduleSlot');
            $parentId = $this->integer('parent_id') ?: null;

            if ($parentId !== null) {
                $parent = FestivalScheduleSlot::query()->find($parentId);

                if (! $parent?->type->isHeader()) {
                    $validator->errors()->add('parent_id', __('app.festival_program_parent_must_be_header'));

                    return;
                }

                if ($slot instanceof FestivalScheduleSlot && $this->parentCreatesCycle($slot, $parent)) {
                    $validator->errors()->add('parent_id', __('app.festival_program_hierarchy_cycle'));
                }
            }

            if ($slot instanceof FestivalScheduleSlot && ! $type->isHeader() && $slot->children()->exists()) {
                $validator->errors()->add('type', __('app.festival_program_header_has_children'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        foreach (['festival_entry_id', 'festival_category_id', 'parent_id', 'starts_at', 'ends_at', 'name', 'notes', 'reschedule_reason', 'editing_item_id'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    private function parentCreatesCycle(FestivalScheduleSlot $slot, FestivalScheduleSlot $parent): bool
    {
        $current = $parent;

        while ($current) {
            if ($current->is($slot)) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }
}
