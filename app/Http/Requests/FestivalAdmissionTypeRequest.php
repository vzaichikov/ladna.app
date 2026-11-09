<?php

namespace App\Http\Requests;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
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
        $assigned = $this->boolean('is_assigned_seating');

        return [
            'confirm_ticket_changes' => ['sometimes', 'boolean'],
            'is_assigned_seating' => ['required', 'boolean'],
            'delivery_mode' => ['required', Rule::enum(FestivalAdmissionDeliveryMode::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'inventory' => [$assigned ? 'exclude' : 'required', 'integer', 'min:1', 'max:1000000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'early_bird_price' => ['nullable', 'required_with:early_bird_ends_at,early_bird_quota', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/', 'lt:price'],
            'early_bird_ends_at' => ['nullable', 'required_with:early_bird_price', 'date_format:Y-m-d\TH:i'],
            'early_bird_quota' => ['nullable', 'integer', 'min:1', 'max:1000000', ...($assigned ? [] : ['lte:inventory'])],
            'sales_starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'sales_ends_at' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:sales_starts_at'],
            'max_per_order' => ['required', 'integer', 'min:1', 'max:20', ...($assigned ? [] : ['lte:inventory'])],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = $this->route('festivalAdmissionType');
        $deliveryMode = $this->input('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value);
        $assigned = $type instanceof FestivalAdmissionType ? $type->is_assigned_seating : $deliveryMode === 'assigned_venue';

        $this->merge([
            'delivery_mode' => $assigned || $deliveryMode === 'assigned_venue' ? FestivalAdmissionDeliveryMode::Venue->value : $deliveryMode,
            'is_assigned_seating' => $assigned,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
