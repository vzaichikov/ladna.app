<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionPlanType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionPlanRequest extends FormRequest
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
            'price_uah' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'currency' => ['required', Rule::in(config('ladna.currencies'))],
            'billing_interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'plan_type' => ['required', Rule::in([SubscriptionPlanType::Standard->value])],
            'access_days' => ['nullable', 'integer', 'min:1', 'max:3660'],
            'public_signup_enabled' => ['nullable', 'boolean'],
            'requires_recurring_payment' => ['nullable', 'boolean'],
            'renewal_lead_days' => ['required', 'integer', 'min:0', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
