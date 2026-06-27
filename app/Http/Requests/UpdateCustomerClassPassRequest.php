<?php

namespace App\Http\Requests;

use App\Enums\CustomerClassPassStatus;
use App\Models\Account;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerClassPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && ($this->user()?->can('manageCustomerClassPasses', $account) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'status' => ['required', Rule::enum(CustomerClassPassStatus::class)],
            'issued_location_id' => [
                'required',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')
                    ->where('account_id', $account?->id),
            ],
            'purchased_at' => ['required', 'date'],
            'opened_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'closed_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'is_paid' => ['nullable', 'boolean'],
        ];
    }
}
