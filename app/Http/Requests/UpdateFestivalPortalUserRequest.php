<?php

namespace App\Http\Requests;

use App\Enums\FestivalPortalRole;
use App\Enums\FestivalRegistrantType;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateFestivalPortalUserRequest extends FormRequest
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
            && $portalUser->role !== FestivalPortalRole::Guest
            && (bool) $this->user()?->can(
                $portalUser->role === FestivalPortalRole::Judge ? 'manageFestivals' : 'manageFestivalRegistrations',
                $account,
            );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $portalUser = $this->route('festivalPortalUser');
        $role = $portalUser instanceof FestivalPortalUser ? $portalUser->role : null;

        return [
            'role' => ['prohibited'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
            'stage_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => [Rule::prohibitedIf($role === FestivalPortalRole::Judge), Rule::requiredIf($role === FestivalPortalRole::Registrant && $this->input('registrant_type') === FestivalRegistrantType::AdultAthlete->value), 'nullable', 'date', 'before_or_equal:today'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique((new FestivalPortalUser)->getTable(), 'email_normalized')
                    ->where(fn ($query) => $query
                        ->where('account_id', $account instanceof Account ? $account->id : 0)
                        ->where('role', $role?->value))
                    ->ignore($portalUser instanceof FestivalPortalUser ? $portalUser->id : 0),
            ],
            'email_normalized' => ['required'],
            'phone' => [
                Rule::requiredIf($role === FestivalPortalRole::Registrant),
                'nullable',
                'string',
                'max:50',
                Rule::unique((new FestivalPortalUser)->getTable(), 'phone_normalized')
                    ->where(fn ($query) => $query
                        ->where('account_id', $account instanceof Account ? $account->id : 0)
                        ->where('role', $role?->value))
                    ->ignore($portalUser instanceof FestivalPortalUser ? $portalUser->id : 0),
            ],
            'phone_normalized' => ['nullable'],
            'registrant_type' => [Rule::prohibitedIf($role === FestivalPortalRole::Judge), Rule::requiredIf($role === FestivalPortalRole::Registrant), 'nullable', Rule::enum(FestivalRegistrantType::class)->only(FestivalRegistrantType::selectableCases($portalUser instanceof FestivalPortalUser ? $portalUser->registrant_type : null))],
            'city' => [Rule::prohibitedIf($role === FestivalPortalRole::Judge), Rule::requiredIf($role === FestivalPortalRole::Registrant), 'nullable', 'string', 'max:255'],
            'studio_name' => [Rule::prohibitedIf($role === FestivalPortalRole::Judge), Rule::requiredIf($role === FestivalPortalRole::Registrant), 'nullable', 'string', 'max:255'],
            'instagram_url' => [Rule::prohibitedIf($role === FestivalPortalRole::Judge), 'nullable', 'url:http,https', 'max:2048'],
            'locale' => ['required', Rule::in(['en', 'uk'])],
            'password' => ['nullable', 'confirmed', Password::defaults(), 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');
            $phone = $this->input('phone_normalized');

            if ($account instanceof Account && filled($phone) && ! app(PhoneNumberNormalizer::class)->isValid($phone, $account->country_code)) {
                $validator->errors()->add('phone', __('app.customer_auth_phone_invalid'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');
        $email = FestivalPortalUser::normalizeEmail((string) $this->input('email'));
        $phone = $account instanceof Account
            ? app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $account->country_code)
            : null;

        $this->merge([
            'email' => $email,
            'email_normalized' => $email,
            'phone' => $phone,
            'phone_normalized' => $phone,
        ]);
    }
}
