<?php

namespace App\Http\Requests;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalAdmissionTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalFinance', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'delivery_mode' => ['required', Rule::enum(FestivalAdmissionDeliveryMode::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'inventory' => ['required', 'integer', 'min:1', 'max:1000000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'early_bird_price' => ['nullable', 'required_with:early_bird_ends_at,early_bird_quota', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/', 'lt:price'],
            'early_bird_ends_at' => ['nullable', 'required_with:early_bird_price', 'date_format:Y-m-d\TH:i'],
            'early_bird_quota' => ['nullable', 'integer', 'min:1', 'max:1000000', 'lte:inventory'],
            'sales_starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'sales_ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:sales_starts_at'],
            'max_per_order' => ['required', 'integer', 'min:1', 'max:20', 'lte:inventory'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'delivery_mode' => $this->input('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
