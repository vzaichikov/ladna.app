<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerClassPassAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sessions_delta' => ['required', 'integer', 'min:1', 'max:500'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
