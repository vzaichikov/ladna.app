<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerClassPassAdjustmentRequest extends FormRequest
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
        return [
            'direction' => ['required', Rule::in(['add', 'subtract'])],
            'sessions_delta' => ['required', 'integer', 'min:1', 'max:500'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function signedSessionsDelta(): int
    {
        $validated = $this->validated();
        $sessionsDelta = (int) $validated['sessions_delta'];

        return $validated['direction'] === 'subtract' ? -$sessionsDelta : $sessionsDelta;
    }
}
