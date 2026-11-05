<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\PhoneNumberNormalizer;
use App\Support\Promotions\PromotionCodeNormalizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalAdmissionOrderRequest extends FormRequest
{
    private ?string $resolvedAccountCountryCode = null;

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
        $phone = trim((string) $this->input('buyer_phone'));
        $normalizedPhone = app(PhoneNumberNormalizer::class)->normalize($phone, $this->accountCountryCode());

        $this->merge([
            'buyer_name' => trim((string) $this->input('buyer_name')),
            'buyer_email' => mb_strtolower(trim((string) $this->input('buyer_email'))),
            'buyer_email_confirmation' => mb_strtolower(trim((string) $this->input('buyer_email_confirmation'))),
            'buyer_phone' => $phone === '' ? null : ($normalizedPhone ?? $phone),
            'promo_code' => app(PromotionCodeNormalizer::class)->normalize($this->input('promo_code')) ?: null,
            'items' => $items,
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
            'promo_code' => ['nullable', 'string', 'min:3', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'items' => ['required_without:seat_ids', 'array', 'max:25'],
            'items.*.admission_type_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'seat_ids' => ['nullable', 'array', 'list', 'max:20'],
            'seat_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'seat_checkout_id' => ['nullable', 'required_with:seat_ids', 'string', 'regex:/^[A-Za-z0-9]{48}$/'],
            'seat_quote' => ['nullable', 'required_with:seat_ids', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'seat_quote_total' => ['nullable', 'required_with:seat_ids', 'integer', 'min:0'],
            'terms' => ['accepted'],
        ];
    }

    /** @return array<string, mixed> */
    public function orderInput(): array
    {
        return $this->validated();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'buyer_name' => __('app.person_name'),
            'buyer_email' => __('app.email'),
            'buyer_email_confirmation' => __('app.email'),
            'buyer_phone' => __('app.phone'),
            'provider' => __('app.payment_provider'),
            'promo_code' => __('app.promo_code'),
            'items' => __('app.festival_tickets'),
            'items.*.quantity' => __('app.event_ticket_quantity'),
            'terms' => __('app.festival_admission_terms'),
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
