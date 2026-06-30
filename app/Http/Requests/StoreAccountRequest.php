<?php

namespace App\Http\Requests;

use App\Rules\PublicSupportLink;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
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
            'default_language' => ['required', Rule::in(['uk', 'en'])],
            'country_code' => ['required', Rule::in(array_keys(config('ladna.countries')))],
            'default_currency' => ['required', Rule::in(['UAH', 'USD', 'EUR'])],
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
            'support_phone_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::phone()],
            'support_secondary_phone_url' => ['nullable', 'string', 'max:2048', PublicSupportLink::phone()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => $this->input('country_code') ?: 'UA',
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
