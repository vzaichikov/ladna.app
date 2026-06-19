<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassPassPlan;
use App\Models\TrainerType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClassPassPlanRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->boolean('allows_any_time')) {
            $this->merge(['any_time_addon_price' => null]);
        }
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
            'slug' => [
                'nullable',
                'alpha_dash:ascii',
                'max:255',
                Rule::unique((new ClassPassPlan)->getTable(), 'slug')->where('account_id', $account?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['required', Rule::in(config('charm.currencies'))],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:999'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:3650'],
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
            'activity_direction_ids' => ['required', 'array', 'min:1'],
            'activity_direction_ids.*' => [
                'integer',
                Rule::exists((new ActivityDirection)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'trainer_type_ids' => ['required', 'array', 'min:1'],
            'trainer_type_ids.*' => [
                'integer',
                Rule::exists((new TrainerType)->getTable(), 'id')->where('account_id', $account?->id),
            ],
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
            },
        ];
    }
}
