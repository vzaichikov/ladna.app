<?php

namespace App\Http\Requests;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateEventFestivalStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');
        $membership = $this->route('membership');

        abort_unless(
            $account instanceof Account
            && $membership instanceof AccountMembership
            && $membership->account_id === $account->id
            && $membership->role === AccountRole::EventFestivalStaff,
            404,
        );

        return $this->user()?->can('manageEventFestivalStaff', $account) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $membership = $this->route('membership');
        $userId = $membership instanceof AccountMembership ? $membership->user_id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique((new User)->getTable(), 'email')->ignore($userId),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => __('app.event_festival_staff_email_taken'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->string('name')->trim()->toString(),
            'email' => $this->string('email')->trim()->lower()->toString(),
        ]);
    }
}
