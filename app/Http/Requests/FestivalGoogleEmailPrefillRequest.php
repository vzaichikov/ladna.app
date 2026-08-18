<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalGoogleEmailPrefillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_email' => ['nullable', 'string', 'max:255'],
            'buyer_email_confirmation' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', Rule::in(['monopay', 'liqpay', 'wayforpay'])],
            'items' => ['nullable', 'array', 'max:25'],
            'items.*' => ['nullable'],
            'terms' => ['sometimes', 'accepted'],
        ];
    }

    /** @return array<string, mixed> */
    public function checkoutDraft(): array
    {
        $draft = collect($this->validated())
            ->only([
                'buyer_name',
                'buyer_email',
                'buyer_email_confirmation',
                'buyer_phone',
                'provider',
                'items',
                'terms',
            ])
            ->all();

        if (isset($draft['items']) && is_array($draft['items'])) {
            $draft['items'] = collect($draft['items'])
                ->filter(fn (mixed $quantity, mixed $admissionTypeId): bool => is_numeric($admissionTypeId))
                ->mapWithKeys(fn (mixed $quantity, mixed $admissionTypeId): array => [
                    (int) $admissionTypeId => (int) (is_array($quantity) ? ($quantity['quantity'] ?? 0) : $quantity),
                ])
                ->all();
        }

        return $draft;
    }
}
