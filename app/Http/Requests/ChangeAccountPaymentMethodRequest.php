<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAccountPaymentMethodRequest extends FormRequest
{
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
        return [
            'return_to' => ['required', Rule::in(['sms_account', 'tariff_payments'])],
        ];
    }

    public function returnRouteName(): string
    {
        return match ($this->validated('return_to')) {
            'sms_account' => 'dashboard.accounts.sms-account.show',
            'tariff_payments' => 'dashboard.accounts.tariff-payments.show',
        };
    }
}
