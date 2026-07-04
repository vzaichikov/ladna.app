<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Location;
use App\Support\DateTimePresenter;
use App\Support\Payments\PaymentAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectCustomerPurchaseRequest extends FormRequest
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
            'location_id' => [
                'required',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'paid_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function amountCents(): int
    {
        return PaymentAmounts::decimalToCents($this->input('amount')) ?? 0;
    }

    public function paidAt(): CarbonImmutable
    {
        return DateTimePresenter::parseAccountDateTime((string) $this->input('paid_at'), $this->route('account')) ?? now()->toImmutable();
    }
}
