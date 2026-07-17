<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\StudioExpense;
use App\Support\DateTimePresenter;
use App\Support\Payments\PaymentAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudioExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && ($this->user()?->can('manageStudioCashflow', $account) ?? false);
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
            'expense_category_id' => [
                'required',
                'integer',
                Rule::exists((new ExpenseCategory)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'payment_method' => ['required', Rule::in(StudioExpense::paymentMethods())],
            'location_id' => [
                Rule::requiredIf($this->input('payment_method') === StudioExpense::PaymentMethodCashdesk),
                'nullable',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id),
            ],
        ];
    }

    public function amountCents(): int
    {
        return PaymentAmounts::decimalToCents($this->input('amount')) ?? 0;
    }

    public function occurredAt(): CarbonImmutable
    {
        return DateTimePresenter::parseAccountDateTime((string) $this->input('occurred_at'), $this->route('account')) ?? now()->toImmutable();
    }
}
