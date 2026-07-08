<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerProfilePhoneOtpVerifyRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => trim((string) $this->input('phone')),
            'code' => preg_replace('/\D+/', '', (string) $this->input('code')),
            'name' => trim((string) $this->input('name')),
            'email' => blank($this->input('email')) ? null : mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
