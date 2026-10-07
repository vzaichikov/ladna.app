<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class QuoteCustomerCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $this->user()?->can('manageClients', $account)
            && $this->user()?->can('issueCustomerClassPasses', $account)
            && $this->user()?->can('recordCustomerPayments', $account);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'array:class_pass_plan_id,quantity'],
            'items.*.class_pass_plan_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'location_id' => ['required', 'integer', 'min:1'],
            'promo_code' => ['nullable', 'string', 'min:3', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $code = app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code'));
        $this->merge(['promo_code' => $code === '' ? null : $code]);
    }
}
