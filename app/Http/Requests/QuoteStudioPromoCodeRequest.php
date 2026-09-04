<?php

namespace App\Http\Requests;

use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QuoteStudioPromoCodeRequest extends FormRequest
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
            'promo_code' => ['required', 'string', 'min:3', 'max:64'],
        ];
    }
}
