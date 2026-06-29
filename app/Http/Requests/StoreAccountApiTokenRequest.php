<?php

namespace App\Http\Requests;

use App\Enums\AccountApiTokenAbility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountApiTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageStudioSettings', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(array_column(AccountApiTokenAbility::cases(), 'value'))],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function abilityValues(): array
    {
        $abilities = $this->validated('abilities', [AccountApiTokenAbility::WebsiteLeadsCreate->value]);

        return is_array($abilities) && $abilities !== []
            ? array_values($abilities)
            : [AccountApiTokenAbility::WebsiteLeadsCreate->value];
    }
}
