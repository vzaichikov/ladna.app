<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\PayrollRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VoidPayrollRunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        $payrollRun = $this->route('payrollRun');

        return $account instanceof Account
            && $payrollRun instanceof PayrollRun
            && $payrollRun->account_id === $account->id
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
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
        ]);
    }
}
