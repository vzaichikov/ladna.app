<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ScheduledClassCancellation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelClosedScheduledClassRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('correctClosedClasses', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pass_effect' => ['required', Rule::in([
                ScheduledClassCancellation::PassEffectReturnSession,
                ScheduledClassCancellation::PassEffectKeepConsumed,
            ])],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
