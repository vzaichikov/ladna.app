<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use App\Support\ScheduleKindRegistry;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesClassPassPlanScheduleKind
{
    /**
     * @return array<int, mixed>
     */
    protected function scheduleKindRules(?Account $account): array
    {
        $eligibleValues = array_values(array_intersect(
            $account?->enabledScheduleKindValues() ?? ScheduleKindRegistry::defaultEnabledValues(),
            ScheduleKindRegistry::classPassEligibleValues(),
        ));

        return [
            'required',
            Rule::in($eligibleValues),
        ];
    }

    protected function validateScheduleKindClassTypes(Validator $validator): void
    {
        $account = $this->route('account');

        if (! $account instanceof Account) {
            return;
        }

        $scheduleKindValue = (string) $this->input('schedule_kind');

        if (! in_array($scheduleKindValue, $account->enabledScheduleKindValues(), true)
            || ! in_array($scheduleKindValue, ScheduleKindRegistry::classPassEligibleValues(), true)) {
            return;
        }

        $classTypeIds = $this->selectedClassTypeIds();

        if ($scheduleKindValue !== ScheduleKind::GroupClass->value
            && $classTypeIds->count() !== 1
            && ! $validator->errors()->has('class_type_ids')) {
            $validator->errors()->add('class_type_ids', __('app.class_pass_plan_single_class_type_required'));
        }

        if ($classTypeIds->isEmpty()) {
            return;
        }

        $classTypes = $account->classTypes()
            ->whereKey($classTypeIds)
            ->get(['id', 'schedule_kind']);

        if ($classTypes->count() !== $classTypeIds->count()) {
            return;
        }

        if ($classTypes->contains(fn (ClassType $classType): bool => $this->scheduleKindValue($classType->schedule_kind) !== $scheduleKindValue)) {
            $validator->errors()->add('class_type_ids', __('app.class_pass_plan_class_type_schedule_kind_mismatch'));
        }
    }

    protected function validateClassPassSegment(Validator $validator): void
    {
        $account = $this->route('account');

        if (! $account instanceof Account || blank($this->input('class_pass_segment_id'))) {
            return;
        }

        $scheduleKindValue = (string) $this->input('schedule_kind');
        $classPassSegment = $account->classPassSegments()
            ->with('activityDirections:id')
            ->whereKey((int) $this->input('class_pass_segment_id'))
            ->first();

        if (! $classPassSegment) {
            return;
        }

        if ($this->scheduleKindValue($classPassSegment->schedule_kind) !== $scheduleKindValue) {
            $validator->errors()->add('class_pass_segment_id', __('app.class_pass_plan_segment_schedule_kind_mismatch'));

            return;
        }

        $directionIds = $classPassSegment->activityDirections->modelKeys();

        if ($directionIds === []) {
            return;
        }

        $classTypeIds = $this->selectedClassTypeIds();

        if ($classTypeIds->isEmpty()) {
            return;
        }

        $invalidClassTypeExists = $account->classTypes()
            ->whereKey($classTypeIds)
            ->whereNotIn('activity_direction_id', $directionIds)
            ->exists();

        if ($invalidClassTypeExists) {
            $validator->errors()->add('class_type_ids', __('app.class_pass_plan_segment_direction_mismatch'));
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function selectedClassTypeIds(): Collection
    {
        return collect($this->input('class_type_ids', []))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function scheduleKindValue(mixed $scheduleKind): string
    {
        return $scheduleKind instanceof ScheduleKind ? $scheduleKind->value : (string) $scheduleKind;
    }
}
