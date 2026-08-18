<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionPlanType;
use App\Models\SubscriptionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignAccountPromoTariffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subscription_plan_id' => [
                'required',
                'integer',
                Rule::exists((new SubscriptionPlan)->getTable(), 'id')
                    ->where('plan_type', SubscriptionPlanType::Promo->value)
                    ->where('public_signup_enabled', false)
                    ->where('requires_recurring_payment', false)
                    ->where('is_active', true),
            ],
        ];
    }
}
