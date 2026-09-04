<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\PhoneNumberNormalizer;
use App\Support\Promotions\PromotionCodeNormalizer;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventOrderRequest extends FormRequest
{
    private ?string $resolvedAccountCountryCode = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = trim((string) $this->input('buyer_phone'));
        $normalizedPhone = app(PhoneNumberNormalizer::class)->normalize($phone, $this->accountCountryCode());

        $this->merge([
            'buyer_name' => trim((string) $this->input('buyer_name')),
            'buyer_email' => mb_strtolower(trim((string) $this->input('buyer_email'))),
            'buyer_email_confirmation' => mb_strtolower(trim((string) $this->input('buyer_email_confirmation'))),
            'buyer_phone' => $phone === '' ? null : ($normalizedPhone ?? $phone),
            'promo_code' => app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_email' => ['required', 'email:rfc', 'max:255', 'confirmed'],
            'buyer_email_confirmation' => ['required', 'email:rfc', 'max:255'],
            'buyer_phone' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && ! $this->phoneIsValid((string) $value)) {
                        $fail(__('app.public_support_phone_invalid'));
                    }
                },
            ],
            'provider' => ['nullable', 'string', Rule::in(['monopay', 'liqpay', 'wayforpay'])],
            'promo_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*' => ['required', 'integer', 'min:0', 'max:100'],
            'accept_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (collect($this->input('items', []))->contains(fn (mixed $quantity): bool => (int) $quantity > 0)) {
                    return;
                }

                $validator->errors()->add('items', __('app.event_ticket_unavailable'));
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderInput(): array
    {
        $input = $this->validated();
        $input['items'] = collect($input['items'])
            ->filter(fn (mixed $quantity): bool => (int) $quantity > 0)
            ->map(fn (mixed $quantity, mixed $ticketTypeId): array => [
                'ticket_type_id' => (int) $ticketTypeId,
                'quantity' => (int) $quantity,
            ])
            ->values()
            ->all();

        return $input;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'buyer_name' => __('app.person_name'),
            'buyer_email' => __('app.email'),
            'buyer_email_confirmation' => __('app.email'),
            'buyer_phone' => __('app.phone'),
            'provider' => __('app.payment_provider'),
            'promo_code' => __('app.promo_code'),
            'items' => __('app.event_tickets'),
            'items.*' => __('app.event_ticket_quantity'),
            'accept_terms' => __('app.event_terms_agreement'),
        ];
    }

    private function accountCountryCode(): string
    {
        return $this->resolvedAccountCountryCode ??= (string) (Account::active()
            ->where('slug', $this->route('accountSlug'))
            ->value('country_code') ?: 'UA');
    }

    private function phoneIsValid(string $phone): bool
    {
        $countryCode = $this->accountCountryCode();
        $normalizer = app(PhoneNumberNormalizer::class);

        if (! $normalizer->isValid($phone, $countryCode)) {
            return false;
        }

        if (strtoupper($countryCode) !== 'UA') {
            return true;
        }

        $digits = preg_replace('/\D+/', '', (string) $normalizer->normalize($phone, $countryCode)) ?: '';

        return ! str_starts_with($digits, '3800');
    }
}
