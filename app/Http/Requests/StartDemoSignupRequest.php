<?php

namespace App\Http\Requests;

use App\Enums\AccountSignupStatus;
use App\Models\AccountSignupRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartDemoSignupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'studio_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique((new AccountSignupRequest)->getTable(), 'owner_email')
                    ->whereIn('status', [
                        AccountSignupStatus::PendingPayment->value,
                        AccountSignupStatus::PaymentStarted->value,
                        AccountSignupStatus::PaymentPaid->value,
                    ]),
            ],
            'owner_phone' => ['nullable', 'string', 'max:64'],
            'owner_password' => ['required', 'string', 'min:6', 'confirmed'],
        ];
    }
}
