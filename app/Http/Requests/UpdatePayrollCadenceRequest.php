<?php

namespace App\Http\Requests;

use App\Enums\PayrollCadence;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayrollCadenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('manageStudioPayroll', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cadence' => ['required', Rule::enum(PayrollCadence::class)],
            'payroll_anchor_date' => [
                'nullable',
                'required_if:cadence,'.PayrollCadence::Biweekly->value,
                'date_format:Y-m-d',
            ],
        ];
    }

    public function cadence(): PayrollCadence
    {
        return PayrollCadence::from((string) $this->validated('cadence'));
    }

    public function anchorDate(): ?string
    {
        if ($this->cadence() !== PayrollCadence::Biweekly) {
            return null;
        }

        return (string) $this->validated('payroll_anchor_date');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payroll_anchor_date.required_if' => __('app.payroll_anchor_date_required'),
        ];
    }
}
