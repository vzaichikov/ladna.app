<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\PhoneNumberNormalizer;
use Closure;
use Illuminate\Contracts\Validation\Validator;
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
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.admission_type_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'terms' => ['accepted'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (collect($this->input('items', []))->contains(fn (mixed $item): bool => is_array($item) && (int) ($item['quantity'] ?? 0) > 0)) {
                    return;
                }

                $validator->errors()->add('items', __('app.festival_admission_unavailable'));
            },
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
