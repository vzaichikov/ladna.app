<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\User;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;

class UpdateAccountOwnerProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('update', $account) ?? false);
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
            'email' => ['required', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')->ignore($this->user())],
            'phone' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max('2mb')],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $countryCode = $this->route('account')?->country_code ?? 'UA';

        $this->merge([
            'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $countryCode),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
