<?php

namespace App\Http\Requests;

use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class QuoteFestivalPromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->map(function (mixed $item, mixed $admissionTypeId): ?array {
                if (is_array($item)) {
                    return [
                        'admission_type_id' => (int) ($item['admission_type_id'] ?? $admissionTypeId),
                        'quantity' => (int) ($item['quantity'] ?? 0),
                    ];
                }

                return is_numeric($admissionTypeId) ? [
                    'admission_type_id' => (int) $admissionTypeId,
                    'quantity' => (int) $item,
                ] : null;
            })
            ->filter(fn (?array $item): bool => $item !== null && $item['quantity'] > 0)
            ->values()
            ->all();

        $this->merge([
            'promo_code' => app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code')),
            'buyer_email' => mb_strtolower(trim((string) $this->input('buyer_email'))) ?: null,
            'buyer_phone' => trim((string) $this->input('buyer_phone')) ?: null,
            'items' => $items,
        ]);
    }

    public function rules(): array
    {
        return [
            'promo_code' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'buyer_email' => ['nullable', 'email:rfc', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.admission_type_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }
}
