<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalPortalUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StaffFestivalParticipantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        $portalUser = $this->route('festivalPortalUser');

        return $account instanceof Account
            && $portalUser instanceof FestivalPortalUser
            && $portalUser->account_id === $account->id
            && (bool) $this->user()?->can('manageFestivalRegistrations', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
