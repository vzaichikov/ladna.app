<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSmsAutoTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'auto_top_up_enabled' => ['nullable', 'boolean'],
            'auto_top_up_threshold_uah' => ['required_if:auto_top_up_enabled,1', 'nullable', 'numeric', 'min:0.01', 'max:100000'],
            'auto_top_up_target_uah' => ['required_if:auto_top_up_enabled,1', 'nullable', 'numeric', 'min:0.01', 'max:100000'],
            'auto_top_up_monthly_cap_uah' => ['required_if:auto_top_up_enabled,1', 'nullable', 'numeric', 'min:0.01', 'max:1000000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('auto_top_up_enabled')) {
                    return;
                }

                $threshold = PaymentAmounts::decimalToCents($this->input('auto_top_up_threshold_uah'));
                $target = PaymentAmounts::decimalToCents($this->input('auto_top_up_target_uah'));
                $cap = PaymentAmounts::decimalToCents($this->input('auto_top_up_monthly_cap_uah'));

                if ($threshold !== null && $target !== null && $threshold >= $target) {
                    $validator->errors()->add('auto_top_up_target_uah', __('app.sms_auto_top_up_target_must_exceed_threshold'));
                }

                if ($cap !== null && $target !== null && $cap < $target) {
                    $validator->errors()->add('auto_top_up_monthly_cap_uah', __('app.sms_auto_top_up_cap_must_cover_target'));
                }
            },
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     threshold_cents: int|null,
     *     target_cents: int|null,
     *     monthly_cap_cents: int|null
     * }
     */
    public function autoTopUpValues(): array
    {
        $enabled = $this->boolean('auto_top_up_enabled');

        return [
            'enabled' => $enabled,
            'threshold_cents' => $enabled ? PaymentAmounts::decimalToCents($this->validated('auto_top_up_threshold_uah')) : null,
            'target_cents' => $enabled ? PaymentAmounts::decimalToCents($this->validated('auto_top_up_target_uah')) : null,
            'monthly_cap_cents' => $enabled ? PaymentAmounts::decimalToCents($this->validated('auto_top_up_monthly_cap_uah')) : null,
        ];
    }
}
