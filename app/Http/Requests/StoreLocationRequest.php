<?php

namespace App\Http\Requests;

use App\Rules\GoogleMapsEmbedUrl;
use App\Support\GoogleMapsEmbedUrlNormalizer;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageStudioSettings', $this->route('account')) ?? false;
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
            'address' => ['nullable', 'string', 'max:2000'],
            'google_maps_embed_url' => ['nullable', 'bail', 'string', 'max:2048', new GoogleMapsEmbedUrl],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $countryCode = $this->route('account')?->country_code ?? 'UA';
        $googleMapsEmbedUrl = $this->input('google_maps_embed_url');

        $this->merge([
            'phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('phone'), $countryCode),
            'email' => blank($this->input('email')) ? null : mb_strtolower(trim((string) $this->input('email'))),
            'google_maps_embed_url' => is_string($googleMapsEmbedUrl)
                ? app(GoogleMapsEmbedUrlNormalizer::class)->normalize($googleMapsEmbedUrl)
                : $googleMapsEmbedUrl,
        ]);
    }
}
