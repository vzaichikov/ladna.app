<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerClassPassValidityAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && ($this->user()?->can('manageCustomerClassPasses', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['add', 'subtract'])],
            'days_delta' => ['required', 'integer', 'min:1', 'max:3650'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function signedDaysDelta(): int
    {
        $validated = $this->validated();
        $daysDelta = (int) $validated['days_delta'];

        return $validated['direction'] === 'subtract' ? -$daysDelta : $daysDelta;
    }
}
