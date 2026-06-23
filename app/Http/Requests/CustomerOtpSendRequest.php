<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerOtpSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:255'],
            'cf-turnstile-response' => ['required', 'string', 'max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => trim((string) $this->input('phone')),
        ]);
    }
}
