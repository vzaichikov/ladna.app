<?php

namespace App\Http\Requests;

use App\Support\Payments\PaymentAmounts;
use Illuminate\Foundation\Http\FormRequest;

class AdjustSmsWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount_uah' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function amountCents(): int
    {
        return PaymentAmounts::decimalToCents($this->validated('amount_uah')) ?? 0;
    }
}
