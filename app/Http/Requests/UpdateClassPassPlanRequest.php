<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ClassType;
use App\Models\Room;
use App\Models\TrainerType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateClassPassPlanRequest extends FormRequest
{
    use ValidatesClassPassPlanScheduleKind;

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('allows_any_time')) {
            $this->merge(['any_time_addon_price' => null]);
        }

        $this->merge([
            'trainer_type_ids' => $this->input('trainer_type_ids', []),
            'room_ids' => $this->input('room_ids', []),
        ]);
    }

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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'schedule_kind' => $this->scheduleKindRules($account instanceof Account ? $account : null),
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['required', Rule::in(config('charm.currencies'))],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:999'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'total_validity_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'available_from_time' => ['nullable', 'date_format:H:i'],
            'available_until_time' => ['nullable', 'date_format:H:i'],
            'allows_any_time' => ['nullable', 'boolean'],
            'any_time_addon_price' => [
                Rule::requiredIf($this->boolean('allows_any_time')),
                'nullable',
                'numeric',
                'min:0',
                'max:999999.99',
                'regex:/^\d+(\.\d{1,2})?$/',
            ],
            'class_type_ids' => ['required', 'array', 'min:1'],
            'class_type_ids.*' => [
                'integer',
                Rule::exists((new ClassType)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'trainer_type_ids' => ['nullable', 'array'],
            'trainer_type_ids.*' => [
                'integer',
                Rule::exists((new TrainerType)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'room_ids' => ['nullable', 'array'],
            'room_ids.*' => [
                'integer',
                Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'is_trial' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('available_from_time')
                    && $this->filled('available_until_time')
                    && $this->input('available_from_time') >= $this->input('available_until_time')) {
                    $validator->errors()->add('available_until_time', __('app.class_pass_plan_time_window_invalid'));
                }

                if ((int) $this->input('total_validity_days') < (int) $this->input('validity_days')) {
                    $validator->errors()->add('total_validity_days', __('app.class_pass_plan_total_validity_too_short'));
                }

                $this->validateScheduleKindClassTypes($validator);
            },
        ];
    }
}
