<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerProfilePhoneOtpSendRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->attributes->get('mobileGuard') === 'customer';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => trim((string) $this->input('phone')),
        ]);
    }
}
