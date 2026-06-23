<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdatePlatformAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
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
            'slug' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'default_language' => ['required', Rule::in(array_keys(config('charm.locales')))],
            'country_code' => ['required', Rule::in(array_keys(config('charm.countries')))],
            'default_currency' => ['required', Rule::in(config('charm.currencies'))],
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max('2mb')],
            'timezone' => ['nullable', 'timezone'],
            'subscription_plan_id' => ['nullable', Rule::exists((new SubscriptionPlan)->getTable(), 'id')],
            'subscription_status' => ['required', Rule::enum(SubscriptionStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => $this->input('country_code') ?: ($this->route('account')?->country_code ?? 'UA'),
        ]);
    }
}
