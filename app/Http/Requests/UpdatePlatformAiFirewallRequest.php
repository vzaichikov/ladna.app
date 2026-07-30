<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformAiFirewallRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firewall_enabled' => ['required', 'boolean'],
            'firewall_user_turns_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'firewall_user_turns_per_hour' => ['required', 'integer', 'min:1', 'max:10000'],
            'firewall_user_turns_per_day' => ['required', 'integer', 'min:1', 'max:100000'],
            'firewall_admin_turns_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'firewall_admin_turns_per_hour' => ['required', 'integer', 'min:1', 'max:10000'],
            'firewall_admin_turns_per_day' => ['required', 'integer', 'min:1', 'max:100000'],
            'firewall_account_turns_per_day' => ['required', 'integer', 'min:1', 'max:1000000'],
            'firewall_user_provider_calls_per_hour' => ['required', 'integer', 'min:1', 'max:10000'],
            'firewall_user_provider_calls_per_day' => ['required', 'integer', 'min:1', 'max:100000'],
            'firewall_admin_provider_calls_per_hour' => ['required', 'integer', 'min:1', 'max:10000'],
            'firewall_admin_provider_calls_per_day' => ['required', 'integer', 'min:1', 'max:100000'],
            'firewall_account_provider_calls_per_day' => ['required', 'integer', 'min:1', 'max:1000000'],
            'firewall_user_out_of_scope_streak' => ['required', 'integer', 'min:1', 'max:100'],
            'firewall_admin_out_of_scope_streak' => ['required', 'integer', 'min:1', 'max:100'],
            'firewall_cooldown_first_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'firewall_cooldown_second_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'firewall_cooldown_third_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'firewall_escalation_reset_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateAscending($validator, [
                    'firewall_user_turns_per_minute',
                    'firewall_user_turns_per_hour',
                    'firewall_user_turns_per_day',
                ]);
                $this->validateAscending($validator, [
                    'firewall_admin_turns_per_minute',
                    'firewall_admin_turns_per_hour',
                    'firewall_admin_turns_per_day',
                ]);
                $this->validateAscending($validator, [
                    'firewall_user_provider_calls_per_hour',
                    'firewall_user_provider_calls_per_day',
                ]);
                $this->validateAscending($validator, [
                    'firewall_admin_provider_calls_per_hour',
                    'firewall_admin_provider_calls_per_day',
                ]);
                $this->validateAscending($validator, [
                    'firewall_cooldown_first_minutes',
                    'firewall_cooldown_second_minutes',
                    'firewall_cooldown_third_minutes',
                ]);

                foreach ([
                    ['firewall_user_turns_per_minute', 'firewall_admin_turns_per_minute'],
                    ['firewall_user_turns_per_hour', 'firewall_admin_turns_per_hour'],
                    ['firewall_user_turns_per_day', 'firewall_admin_turns_per_day'],
                    ['firewall_user_provider_calls_per_hour', 'firewall_admin_provider_calls_per_hour'],
                    ['firewall_user_provider_calls_per_day', 'firewall_admin_provider_calls_per_day'],
                    ['firewall_user_out_of_scope_streak', 'firewall_admin_out_of_scope_streak'],
                ] as [$normalField, $adminField]) {
                    if ((int) $this->input($adminField) < (int) $this->input($normalField)) {
                        $validator->errors()->add($adminField, __('app.ai_firewall_admin_limit_validation'));
                    }
                }
            },
        ];
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function validateAscending(Validator $validator, array $fields): void
    {
        $previous = null;

        foreach ($fields as $field) {
            $value = (int) $this->input($field);

            if ($previous !== null && $value < $previous) {
                $validator->errors()->add($field, __('app.ai_firewall_ascending_validation'));
            }

            $previous = $value;
        }
    }
}
