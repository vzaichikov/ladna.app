<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalClassificationAxis;
use App\Models\FestivalEdition;
use App\Support\FestivalCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalClassificationAxisRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        $edition = $this->route('festivalEdition');
        $axis = $this->route('festivalClassificationAxis');

        return [
            'code' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('festival_classification_axes')->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)->ignore($axis instanceof FestivalClassificationAxis ? $axis->id : null)],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['direction', 'style', 'age', 'level', 'entry_format', 'custom'])],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $edition = $this->route('festivalEdition');
        $axis = $this->route('festivalClassificationAxis');
        $kind = $axis instanceof FestivalClassificationAxis ? $axis->kind : (string) $this->input('kind');

        if (! $edition instanceof FestivalEdition) {
            return;
        }

        $this->merge([
            'code' => $axis instanceof FestivalClassificationAxis
                ? $axis->code
                : FestivalCodeGenerator::unique(
                    (string) $this->input('name'),
                    $kind === 'direction' ? 'direction' : 'classification',
                    fn (string $candidate): bool => $edition->axes()->where('code', $candidate)->exists(),
                ),
            'kind' => $kind === 'direction' ? 'direction' : $this->input('kind'),
        ]);
    }
}
