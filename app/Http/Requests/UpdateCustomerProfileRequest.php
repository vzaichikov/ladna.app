<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateCustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $customer = $this->user('customer');
        $accountId = $customer?->account_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique((new Customer)->getTable(), 'email')
                    ->where('account_id', $accountId)
                    ->ignore($customer),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $countryCode = $this->user('customer')?->account()->value('country_code') ?? 'UA';

                if (! app(PhoneNumberNormalizer::class)->isValid($this->input('phone'), $countryCode)) {
                    $validator->errors()->add('phone', __('app.customer_auth_phone_invalid'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $countryCode = $this->user('customer')?->account()->value('country_code') ?? 'UA';

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $countryCode),
            'email' => blank($this->input('email')) ? null : mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
