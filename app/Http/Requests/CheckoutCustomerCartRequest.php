<?php

namespace App\Http\Requests;

use App\Models\CustomerPurchase;
use Illuminate\Validation\Rule;

class CheckoutCustomerCartRequest extends QuoteCustomerCartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'quote_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'idempotency_key' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::in(CustomerPurchase::paymentMethods())],
            'provider' => ['nullable', Rule::in(['monopay', 'liqpay', 'wayforpay'])],
            'payment_received' => ['nullable', 'boolean'],
        ];
    }
}
