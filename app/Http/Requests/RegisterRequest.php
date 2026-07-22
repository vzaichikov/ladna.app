<?php

namespace App\Http\Requests;

use App\Support\Onboarding\PublicOwnerOnboardingAvailability;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'legal_accepted' => ['accepted'],
            'cf-turnstile-response' => [
                Rule::requiredIf(app(PublicOwnerOnboardingAvailability::class)->turnstileRequired()),
                'nullable',
                'string',
                'max:4096',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('phone')) {
                    return;
                }

                if (! app(PhoneNumberNormalizer::class)->isValid($this->input('phone'), 'UA')) {
                    $validator->errors()->add('phone', __('app.onboarding.phone_invalid'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), 'UA'),
        ]);
    }
}
