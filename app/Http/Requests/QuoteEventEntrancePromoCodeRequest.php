<?php

namespace App\Http\Requests;

use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class QuoteEventEntrancePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'promo_code' => app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code')),
            'guest_email' => mb_strtolower(trim((string) $this->input('guest_email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'promo_code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'guest_email' => ['required', 'email:rfc', 'max:255'],
            'ticket_type_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
