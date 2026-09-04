<?php

namespace App\Http\Requests;

use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class QuoteEventPromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'promo_code' => app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code')),
            'buyer_email' => filled($this->input('buyer_email'))
                ? mb_strtolower(trim((string) $this->input('buyer_email')))
                : null,
            'buyer_phone' => filled($this->input('buyer_phone')) ? trim((string) $this->input('buyer_phone')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'promo_code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'buyer_email' => ['nullable', 'email:rfc', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! collect($this->input('items', []))->contains(fn (mixed $quantity): bool => (int) $quantity > 0)) {
                    $validator->errors()->add('items', __('app.event_ticket_unavailable'));
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function quoteInput(): array
    {
        $input = $this->validated();
        $input['items'] = collect($input['items'])
            ->filter(fn (mixed $quantity): bool => (int) $quantity > 0)
            ->mapWithKeys(fn (mixed $quantity, mixed $ticketTypeId): array => [
                (int) $ticketTypeId => (int) $quantity,
            ])
            ->all();

        return $input;
    }
}
