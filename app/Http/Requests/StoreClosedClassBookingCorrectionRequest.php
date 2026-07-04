<?php

namespace App\Http\Requests;

use App\Enums\ClassBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClosedClassBookingCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && ($this->user()?->can('correctClosedClasses', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists((new Customer)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'status' => ['required', Rule::enum(ClassBookingStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function status(): ClassBookingStatus
    {
        return ClassBookingStatus::from((string) $this->validated('status'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status') ?: ClassBookingStatus::Attended->value,
        ]);
    }
}
