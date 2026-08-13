<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FestivalProfilePhoneOtpSendRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('festival') instanceof FestivalPortalUser;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $portalUser = $this->user('festival');
        $account = $this->attributes->get('festivalAccount');

        if (! $portalUser instanceof FestivalPortalUser) {
            return [];
        }

        return [
            'phone' => [
                'required',
                'string',
                'max:50',
                Rule::unique((new FestivalPortalUser)->getTable(), 'phone_normalized')
                    ->where(fn ($query) => $query
                        ->where('account_id', $account instanceof Account ? $account->id : 0)
                        ->where('role', $portalUser->role->value))
                    ->ignore($portalUser->id),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $portalUser = $this->user('festival');
            $account = $this->attributes->get('festivalAccount');
            $phone = $this->input('phone');

            if ($portalUser instanceof FestivalPortalUser
                && $account instanceof Account
                && filled($phone)
                && ! app(PhoneNumberNormalizer::class)->isValid($phone, $account->country_code)) {
                $validator->errors()->add('phone', __('app.customer_auth_phone_invalid'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $portalUser = $this->user('festival');
        $account = $this->attributes->get('festivalAccount');

        if ($portalUser instanceof FestivalPortalUser
            && $account instanceof Account) {
            $this->merge([
                'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $account->country_code),
            ]);
        }
    }
}
