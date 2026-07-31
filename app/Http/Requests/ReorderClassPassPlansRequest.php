<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\ClassPassSegment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReorderClassPassPlansRequest extends FormRequest
{
    use ValidatesClassPassPlanScheduleKind;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'schedule_kind' => $this->scheduleKindRules($account instanceof Account ? $account : null),
            'class_pass_segment_id' => [
                'nullable',
                'integer',
                Rule::exists((new ClassPassSegment)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'plan_ids' => ['required', 'array', 'min:1', 'max:3276'],
            'plan_ids.*' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists((new ClassPassPlan)->getTable(), 'id')->where('account_id', $account?->id),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $account = $this->route('account');

                if (! $account instanceof Account) {
                    return;
                }

                $scheduleKind = (string) $this->input('schedule_kind');
                $segmentId = $this->filled('class_pass_segment_id')
                    ? (int) $this->input('class_pass_segment_id')
                    : null;

                if ($segmentId !== null) {
                    $segment = $account->classPassSegments()->whereKey($segmentId)->first();
                    $segmentScheduleKind = $segment?->schedule_kind?->value ?? (string) $segment?->schedule_kind;

                    if ($segmentScheduleKind !== $scheduleKind) {
                        $validator->errors()->add(
                            'class_pass_segment_id',
                            __('app.class_pass_plan_segment_schedule_kind_mismatch'),
                        );

                        return;
                    }
                }

                $expectedPlanIds = $account->classPassPlans()
                    ->where('schedule_kind', $scheduleKind)
                    ->when(
                        $segmentId === null,
                        fn ($query) => $query->whereNull('class_pass_segment_id'),
                        fn ($query) => $query->where('class_pass_segment_id', $segmentId),
                    )
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
                $submittedPlanIds = collect($this->input('plan_ids', []))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();

                if ($submittedPlanIds !== $expectedPlanIds) {
                    $validator->errors()->add('plan_ids', __('app.class_pass_plan_order_scope_invalid'));
                }
            },
        ];
    }
}
