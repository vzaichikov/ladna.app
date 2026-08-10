<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalContentSection;
use App\Models\FestivalEdition;
use App\Support\FestivalCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalContentSectionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $edition = $this->route('festivalEdition');
        $section = $this->route('festivalContentSection');

        if (! $edition instanceof FestivalEdition) {
            return;
        }

        $this->merge([
            'key' => $section instanceof FestivalContentSection
                ? $section->key
                : FestivalCodeGenerator::unique(
                    (string) $this->input('title'),
                    'section',
                    fn (string $candidate): bool => $edition->sections()->where('key', $candidate)->exists(),
                ),
        ]);
    }

    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        $edition = $this->route('festivalEdition');
        $section = $this->route('festivalContentSection');

        return [
            'key' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('festival_content_sections')->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)->ignore($section instanceof FestivalContentSection ? $section->id : null)],
            'title' => ['required', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:100000'],
            'visibility' => ['required', Rule::in(['public', 'portal', 'staff'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
