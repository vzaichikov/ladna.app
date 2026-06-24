<?php

namespace App\Http\Requests\Api;

use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWebsiteLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->attributes->has('account');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'source_page' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->attributes->get('account');
        $countryCode = $account?->country_code ?? 'UA';

        $this->merge([
            'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $countryCode),
        ]);
    }
}
