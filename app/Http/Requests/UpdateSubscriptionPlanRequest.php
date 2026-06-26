<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionPlanType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
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
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:99999999'],
            'currency' => ['required', Rule::in(config('charm.currencies'))],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'plan_type' => ['required', Rule::enum(SubscriptionPlanType::class)],
            'access_days' => ['nullable', 'integer', 'min:1', 'max:3660'],
            'public_signup_enabled' => ['nullable', 'boolean'],
            'requires_recurring_payment' => ['nullable', 'boolean'],
            'renewal_lead_days' => ['required', 'integer', 'min:0', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
