<?php

namespace App\Http\Requests;

use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventGoogleEmailPrefillRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'promo_code' => app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code')) ?: null,
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
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_email' => ['nullable', 'string', 'max:255'],
            'buyer_email_confirmation' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', Rule::in(['monopay', 'liqpay', 'wayforpay'])],
            'promo_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'items' => ['nullable', 'array', 'max:25'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:100'],
            'accept_terms' => ['sometimes', 'accepted'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkoutDraft(): array
    {
        $draft = collect($this->validated())
            ->only([
                'buyer_name',
                'buyer_email',
                'buyer_email_confirmation',
                'buyer_phone',
                'provider',
                'promo_code',
                'items',
                'accept_terms',
            ])
            ->all();

        if (isset($draft['items']) && is_array($draft['items'])) {
            $draft['items'] = collect($draft['items'])
                ->filter(fn (mixed $quantity, mixed $ticketTypeId): bool => is_numeric($ticketTypeId))
                ->mapWithKeys(fn (mixed $quantity, mixed $ticketTypeId): array => [(int) $ticketTypeId => (int) $quantity])
                ->all();
        }

        return $draft;
    }
}
