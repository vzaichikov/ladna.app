<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Location;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCustomerClassPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && ($this->user()?->can('issueCustomerClassPasses', $account) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'class_pass_plan_id' => [
                'required',
                'integer',
                Rule::exists((new ClassPassPlan)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('is_active', true),
            ],
            'issued_location_id' => [
                'required',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('is_active', true),
            ],
            'is_paid' => ['nullable', 'boolean'],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('paid_amount')) {
                    return;
                }

                $account = $this->route('account');

                if (! $account instanceof Account) {
                    return;
                }

                $classPassPlan = $account->classPassPlans()
                    ->whereKey($this->input('class_pass_plan_id'))
                    ->first();

                if (! $classPassPlan) {
                    return;
                }

                if ($this->paidAmountCents() > (int) $classPassPlan->price_cents) {
                    $validator->errors()->add('paid_amount', __('app.class_pass_payment_amount_too_high'));
                }
            },
        ];
    }

    public function paidAmountCents(): int
    {
        if ($this->boolean('is_paid')) {
            return PHP_INT_MAX;
        }

        return PaymentAmounts::decimalToCents($this->input('paid_amount', 0)) ?? 0;
    }
}
