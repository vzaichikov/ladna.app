<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ClassBookingCorrection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RemoveClosedClassBookingRequest extends FormRequest
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
        return [
            'pass_effect' => [
                'required',
                Rule::in([
                    ClassBookingCorrection::PassEffectReturnSession,
                    ClassBookingCorrection::PassEffectKeepConsumed,
                ]),
            ],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
