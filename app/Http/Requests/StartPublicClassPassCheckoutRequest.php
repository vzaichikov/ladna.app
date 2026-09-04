<?php

namespace App\Http\Requests;

use App\Enums\IntegrationProvider;
use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartPublicClassPassCheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'promo_code' => app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code')),
        ]);
    }

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
            'studio_rules_accepted' => ['accepted'],
            'provider' => ['nullable', Rule::in([
                IntegrationProvider::Monopay->value,
                IntegrationProvider::Liqpay->value,
                IntegrationProvider::Wayforpay->value,
            ])],
            'promo_code' => ['nullable', 'string', 'min:3', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'studio_rules_accepted.accepted' => __('app.studio_rules_accepted'),
        ];
    }
}
