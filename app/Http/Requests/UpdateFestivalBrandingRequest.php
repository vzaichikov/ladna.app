<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalLandingRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFestivalBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');

        return $account instanceof Account
            && $edition instanceof FestivalEdition
            && $edition->account_id === $account->id
            && ($this->user()?->can('manageFestivals', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $registry = app(FestivalLandingRegistry::class);

        return [
            'landing_template' => [
                'sometimes',
                'required',
                'string',
                Rule::in($account instanceof Account ? $registry->availableTemplateKeys($account) : []),
            ],
            'landing_palette' => ['required', 'string', Rule::in(array_keys($registry->palettes()))],
            'hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
            'mobile_hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
        ];
    }

    /** @return array{landing_palette: string, landing_template?: string} */
    public function brandingPayload(): array
    {
        $payload = ['landing_palette' => (string) $this->validated('landing_palette')];

        if ($this->filled('landing_template')) {
            $payload['landing_template'] = (string) $this->validated('landing_template');
        }

        return $payload;
    }
}
