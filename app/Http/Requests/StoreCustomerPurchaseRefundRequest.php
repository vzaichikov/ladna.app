<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseItem;
use App\Models\CustomerPurchaseRefund;
use App\Models\Location;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerPurchaseRefundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        $customerPurchase = $this->route('customerPurchase');

        return $account instanceof Account
            && $customerPurchase instanceof CustomerPurchase
            && $customerPurchase->account_id === $account->id
            && $account->isOwnedBy($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $purchase = $this->route('customerPurchase');
        $hasItems = $purchase instanceof CustomerPurchase && $purchase->items()->exists();

        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(CustomerPurchaseRefund::methods())],
            'cash_location_id' => [
                Rule::requiredIf($this->input('method') === CustomerPurchaseRefund::MethodCash),
                'nullable',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')
                    ->where('account_id', $account instanceof Account ? $account->id : 0),
            ],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'items' => [Rule::requiredIf($hasItems), Rule::prohibitedIf(! $hasItems), 'array', 'min:1', 'max:1000'],
            'items.*' => ['array:customer_purchase_item_id,amount'],
            'items.*.customer_purchase_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists((new CustomerPurchaseItem)->getTable(), 'id')
                    ->where('account_id', $account instanceof Account ? $account->id : 0)
                    ->where('customer_purchase_id', $purchase instanceof CustomerPurchase ? $purchase->id : 0),
            ],
            'items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    public function amountCents(): int
    {
        return PaymentAmounts::decimalToCents($this->validated('amount')) ?? 0;
    }

    public function method(): string
    {
        return (string) $this->validated('method');
    }

    public function cashLocationId(): ?int
    {
        $cashLocationId = $this->validated('cash_location_id');

        return filled($cashLocationId) ? (int) $cashLocationId : null;
    }

    /**
     * @return array<int, array{customer_purchase_item_id: int, amount_cents: int}>
     */
    public function itemAllocations(): array
    {
        return collect($this->validated('items', []))
            ->map(fn (array $item): array => [
                'customer_purchase_item_id' => (int) $item['customer_purchase_item_id'],
                'amount_cents' => PaymentAmounts::decimalToCents($item['amount']) ?? 0,
            ])
            ->values()
            ->all();
    }
}
