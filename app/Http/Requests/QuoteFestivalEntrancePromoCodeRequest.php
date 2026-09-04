<?php

namespace App\Http\Requests;

use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class QuoteFestivalEntrancePromoCodeRequest extends FormRequest
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
            'promo_code' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'ticket_type_id' => ['required', 'integer', 'min:1'],
            'guest_email' => ['required', 'email:rfc', 'max:255'],
        ];
    }
}
