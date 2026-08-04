<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\WorkingLocationContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkingLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('view', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $locationValues = $account instanceof Account
            ? $account->locations()->active()->pluck('id')->map(fn (int $id): string => (string) $id)->all()
            : [];

        return [
            'location_context' => ['required', 'string', Rule::in([WorkingLocationContext::All, ...$locationValues])],
            'redirect_to' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
