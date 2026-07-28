<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['items' => collect($this->input('items', []))
            ->filter(fn ($quantity): bool => (int) $quantity > 0)
            ->map(fn ($quantity, $ticketTypeId): array => [
                'ticket_type_id' => (int) $ticketTypeId,
                'quantity' => (int) $quantity,
            ])->values()->all()]);
    }

    public function rules(): array
    {
        return [
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_email' => ['required', 'email:rfc', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', Rule::in(['monopay', 'liqpay', 'wayforpay'])],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.ticket_type_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'accept_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'buyer_name' => __('app.person_name'),
            'buyer_email' => __('app.email'),
            'buyer_phone' => __('app.phone'),
            'provider' => __('app.payment_provider'),
            'items' => __('app.event_tickets'),
            'items.*.ticket_type_id' => __('app.event_ticket_option'),
            'items.*.quantity' => __('app.event_ticket_quantity'),
            'accept_terms' => __('app.event_terms_agreement'),
        ];
    }
}
