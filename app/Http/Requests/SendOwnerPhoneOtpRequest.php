<?php

namespace App\Http\Requests;

use App\Support\Onboarding\PublicOwnerOnboardingAvailability;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendOwnerPhoneOtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isPlatformAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'cf-turnstile-response' => [
                Rule::requiredIf(app(PublicOwnerOnboardingAvailability::class)->turnstileRequired()),
                'nullable',
                'string',
                'max:4096',
            ],
        ];
    }
}
