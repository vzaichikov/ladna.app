<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicEntranceOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ticket_type_id' => ['required', 'integer', 'min:1'],
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_email' => ['nullable', 'email:rfc', 'max:255'],
            'provider' => ['required', 'string', 'max:40'],
            'idempotency_key' => ['required', 'uuid'],
            'terms_accepted' => ['accepted'],
        ];
    }

    /** @return array<string, mixed> */
    public function saleInput(): array
    {
        return $this->validated();
    }

    protected function prepareForValidation(): void
    {
        $email = trim($this->string('guest_email', $this->string('email')->toString())->toString());

        $this->merge([
            'guest_name' => preg_replace('/\s+/u', ' ', trim($this->string('guest_name')->toString())),
            'guest_email' => $email === '' ? null : mb_strtolower($email),
            'provider' => trim($this->string('provider')->toString()),
        ]);
    }
}
