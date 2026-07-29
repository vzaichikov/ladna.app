<?php

namespace App\Http\Requests;

use App\Enums\AccountApiTokenAbility;
use App\Models\Account;
use App\Support\AccountApiTokenAbilityAuthorizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountApiTokenRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('abilities')) {
            $this->merge([
                'abilities' => [AccountApiTokenAbility::WebsiteLeadsCreate->value],
            ]);
        }
    }

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
    public function rules(AccountApiTokenAbilityAuthorizer $abilityAuthorizer): array
    {
        $account = $this->route('account');
        $abilityValues = $account instanceof Account
            ? array_column($abilityAuthorizer->grantableAbilities($account, $this->user()), 'value')
            : [];

        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in($abilityValues)],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function abilityValues(): array
    {
        $abilities = $this->validated('abilities');

        return is_array($abilities) ? array_values($abilities) : [];
    }
}
