<?php

namespace App\Http\Requests;

use App\Enums\FestivalPortalRole;
use App\Enums\FestivalRegistrantType;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class FestivalPortalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('festival')?->is_active === true;
    }

    public function rules(): array
    {
        $portalUser = $this->user('festival');
        $account = $this->attributes->get('festivalAccount');
        $role = $portalUser?->role;
        $isRegistrant = $role === FestivalPortalRole::Registrant;
        $isGuest = $role === FestivalPortalRole::Guest;

        return [
            'role' => ['prohibited'],
            'account_id' => ['prohibited'],
            'google_id' => ['prohibited'],
            'profile_action' => [Rule::prohibitedIf(! $isRegistrant), 'nullable', 'string', Rule::in(['send_phone_otp'])],
            'registrant_type' => [Rule::prohibitedIf(! $isRegistrant), Rule::requiredIf($isRegistrant), 'nullable', Rule::enum(FestivalRegistrantType::class)->only(FestivalRegistrantType::selectableCases($portalUser?->registrant_type))],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'patronymic' => [Rule::prohibitedIf($isGuest), 'nullable', 'string', 'max:255'],
            'stage_name' => [Rule::prohibitedIf($isGuest), 'nullable', 'string', 'max:255'],
            'date_of_birth' => [Rule::prohibitedIf(! $isRegistrant), Rule::requiredIf($isRegistrant && $this->input('registrant_type') === FestivalRegistrantType::AdultAthlete->value), 'nullable', 'date', 'before_or_equal:today'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique((new FestivalPortalUser)->getTable(), 'email_normalized')
                    ->where(fn ($query) => $query->where('account_id', $account instanceof Account ? $account->id : 0)->where('role', $role?->value))
                    ->ignore($portalUser?->id ?? 0),
            ],
            'email_normalized' => ['required'],
            'phone' => [
                Rule::requiredIf($isRegistrant),
                'nullable',
                'string',
                'max:50',
                Rule::unique((new FestivalPortalUser)->getTable(), 'phone_normalized')
                    ->where(fn ($query) => $query->where('account_id', $account instanceof Account ? $account->id : 0)->where('role', $role?->value))
                    ->ignore($portalUser?->id ?? 0),
            ],
            'phone_normalized' => ['nullable'],
            'city' => [Rule::prohibitedIf(! $isRegistrant), Rule::requiredIf($isRegistrant), 'nullable', 'string', 'max:255'],
            'studio_name' => [Rule::prohibitedIf(! $isRegistrant), Rule::requiredIf($isRegistrant), 'nullable', 'string', 'max:255'],
            'instagram_url' => [Rule::prohibitedIf(! $isRegistrant), 'nullable', 'url:http,https', 'max:2048'],
            'telegram_contact' => [Rule::prohibitedIf(! $isRegistrant), 'nullable', 'string', 'max:255', 'regex:/^(?:@?[A-Za-z0-9_]+|(?:https?:\/\/)?t\.me\/[A-Za-z0-9_]+\/?|\d+)$/i'],
            'locale' => ['required', Rule::in(['en', 'uk'])],
            'password' => ['nullable', 'confirmed', Password::defaults(), 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'telegram_contact.regex' => __('app.festival_telegram_contact_invalid'),
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->attributes->get('festivalAccount');
            $phone = $this->input('phone_normalized');

            if ($account instanceof Account && filled($phone) && ! app(PhoneNumberNormalizer::class)->isValid($phone, $account->country_code)) {
                $validator->errors()->add('phone', __('app.customer_auth_phone_invalid'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->attributes->get('festivalAccount');
        $email = FestivalPortalUser::normalizeEmail((string) $this->input('email'));
        $phone = $account instanceof Account
            ? app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $account->country_code)
            : null;

        $this->merge([
            'email' => $email,
            'email_normalized' => $email,
            'phone' => $phone,
            'phone_normalized' => $phone,
            'telegram_contact' => filled($this->input('telegram_contact')) ? trim((string) $this->input('telegram_contact')) : null,
        ]);
    }
}
