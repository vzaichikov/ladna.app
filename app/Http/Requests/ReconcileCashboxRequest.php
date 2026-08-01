<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Location;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReconcileCashboxRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('manageStudioCashflow', $account) ?? false);
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
            'location_id' => [
                'required',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'actual_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function actualCountedCents(): int
    {
        return PaymentAmounts::decimalToCents($this->validated('actual_amount')) ?? 0;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->input('idempotency_key') ?: (string) Str::uuid(),
        ]);
    }
}
