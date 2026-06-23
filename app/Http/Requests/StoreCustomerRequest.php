<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageClients', $this->route('account')) ?? false;
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique((new Customer)->getTable(), 'email')->where('account_id', $account?->id)],
            'phone' => ['nullable', 'string', 'max:255', Rule::unique((new Customer)->getTable(), 'phone')->where('account_id', $account?->id)],
            'password' => ['nullable', Password::defaults()],
            'default_language' => ['nullable', Rule::in(array_keys(config('charm.locales')))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $countryCode = $this->route('account')?->country_code ?? 'UA';

        $this->merge([
            'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $countryCode),
            'email' => blank($this->input('email')) ? null : mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
