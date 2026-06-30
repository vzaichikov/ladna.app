<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionPlan;
use App\Rules\PublicSupportLink;
use App\Rules\PublicSupportPhone;
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
            'default_language' => ['required', Rule::in(array_keys(config('ladna.locales')))],
            'country_code' => ['required', Rule::in(array_keys(config('ladna.countries')))],
            'default_currency' => ['required', Rule::in(config('ladna.currencies'))],
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'studio_slogan' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max('2mb')],
            'timezone' => ['nullable', 'timezone'],
            'legal_entity_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'support_instagram_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::instagram()],
            'support_telegram_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::telegram()],
            'support_viber_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::viber()],
            'support_whatsapp_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::whatsapp()],
            'support_phone_url' => ['nullable', 'string', 'max:64', new PublicSupportPhone],
            'support_secondary_phone_url' => ['nullable', 'string', 'max:64', new PublicSupportPhone],
            'subscription_plan_id' => ['nullable', Rule::exists((new SubscriptionPlan)->getTable(), 'id')],
            'subscription_status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'subscription_ends_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => $this->input('country_code') ?: ($this->route('account')?->country_code ?? 'UA'),
            ...$this->normalizedOptionalPublicFields(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function normalizedOptionalPublicFields(): array
    {
        $normalized = [];

        foreach (['studio_slogan', 'support_instagram_url', 'support_telegram_url', 'support_viber_url', 'support_whatsapp_url', 'support_phone_url', 'support_secondary_phone_url'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            $normalized[$field] = blank($value) ? null : trim((string) $value);
        }

        return $normalized;
    }
}
