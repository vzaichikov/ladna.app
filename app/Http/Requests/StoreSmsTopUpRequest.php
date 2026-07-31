<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\Sms\SmsServiceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSmsTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(SmsServiceSettings $settings): array
    {
        return [
            'amount_cents' => [
                'required',
                'integer',
                Rule::in($settings->topUpPresetsCents()),
            ],
        ];
    }

    public function amountCents(): int
    {
        return (int) $this->validated('amount_cents');
    }
}
