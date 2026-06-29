<?php

namespace App\Http\Requests;

use App\Enums\CustomerClassPassStatus;
use App\Models\Account;
use App\Models\CustomerClassPass;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $customerClassPass = $this->route('customerClassPass');

                if (! $customerClassPass instanceof CustomerClassPass) {
                    return;
                }

                $requestedStatus = (string) $this->input('status');
                $isCurrentlyFreezed = $customerClassPass->status === CustomerClassPassStatus::Freezed;
                $isRequestingFreezed = $requestedStatus === CustomerClassPassStatus::Freezed->value;

                if ($isCurrentlyFreezed !== $isRequestingFreezed) {
                    $validator->errors()->add('status', __('app.class_pass_freeze_status_requires_action'));
                }

                if ($isCurrentlyFreezed && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', __('app.class_pass_freeze_active_requires_action'));
                }
            },
        ];
    }
}
