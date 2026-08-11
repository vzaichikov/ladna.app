<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Support\FestivalCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalDirectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        $edition = $this->route('festivalEdition');
        $direction = $this->route('festivalDirection');

        return [
            'code' => [
                'required',
                'alpha_dash:ascii',
                'max:100',
                Rule::unique((new FestivalDirection)->getTable(), 'code')
                    ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : null)
                    ->ignore($direction instanceof FestivalDirection ? $direction->id : null),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $edition = $this->route('festivalEdition');
        $direction = $this->route('festivalDirection');

        if (! $edition instanceof FestivalEdition) {
            return;
        }

        $this->merge([
            'code' => $direction instanceof FestivalDirection
                ? $direction->code
                : FestivalCodeGenerator::unique(
                    (string) $this->input('name'),
                    'direction',
                    fn (string $candidate): bool => $edition->directions()->where('code', $candidate)->exists(),
                ),
        ]);
    }
}
