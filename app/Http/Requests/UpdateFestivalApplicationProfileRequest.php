<?php

namespace App\Http\Requests;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFestivalApplicationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->attributes->get('festivalAccount');
        $portalUser = $this->user('festival');

        return $account instanceof Account
            && $portalUser instanceof FestivalPortalUser
            && $portalUser->is_active
            && $portalUser->role === FestivalPortalRole::Registrant
            && $portalUser->account_id === $account->id;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'studio_name' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => $this->trimmedInput('first_name'),
            'last_name' => $this->trimmedInput('last_name'),
            'city' => $this->trimmedInput('city'),
            'studio_name' => $this->trimmedInput('studio_name'),
        ]);
    }

    private function trimmedInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }
}
