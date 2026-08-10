<?php

namespace App\Http\Requests;

use App\Enums\FestivalFieldScope;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementType;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalRequirementDefinition;
use App\Support\FestivalCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalRequirementRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $edition = $this->route('festivalEdition');
        $requirement = $this->route('festivalRequirementDefinition');
        $existingOptions = collect($requirement instanceof FestivalRequirementDefinition ? $requirement->options : [])->values();
        $existingOptionValues = $existingOptions->pluck('value')->filter()->map(fn (mixed $value): string => (string) $value);
        $reservedOptionValues = $existingOptionValues->values()->all();
        $options = collect($this->input('options', []))
            ->filter(fn (mixed $option): bool => is_array($option) && filled($option['label'] ?? null))
            ->map(function (array $option) use ($existingOptionValues, &$reservedOptionValues): array {
                $originalValue = (string) ($option['original_value'] ?? '');
                $value = $existingOptionValues->contains($originalValue)
                    ? $originalValue
                    : FestivalCodeGenerator::unique(
                        (string) $option['label'],
                        'option',
                        fn (): bool => false,
                        $reservedOptionValues,
                    );
                $reservedOptionValues[] = $value;
                unset($option['original_value']);

                return [...$option, 'value' => $value];
            })
            ->values()
            ->all();
        $optionPrices = collect($this->input('option_prices', []))
            ->filter(fn (mixed $amount, mixed $key): bool => filled($key) && filled($amount))
            ->all();
        foreach ($options as $option) {
            if (filled($option['price_cents'] ?? null)) {
                $optionPrices[$option['value']] = $option['price_cents'];
            }
        }

        $list = fn (string $key): array => collect(preg_split('/[,\n]+/', (string) $this->input($key), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $prepared = [
            'options' => $options,
            'option_prices' => $optionPrices,
            'allowed_extensions' => $list('allowed_extensions_text'),
            'allowed_mime_types' => $list('allowed_mime_types_text'),
        ];

        if ($edition instanceof FestivalEdition) {
            $prepared['code'] = $requirement instanceof FestivalRequirementDefinition && filled($requirement->code)
                ? $requirement->code
                : FestivalCodeGenerator::unique(
                    (string) $this->input('name'),
                    'requirement',
                    fn (string $candidate): bool => $edition->festivalRequirementDefinitions()->where('code', $candidate)->exists(),
                );
        }

        $this->merge($prepared);
    }

    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    public function rules(): array
    {
        return [
            'festival_category_id' => ['nullable', 'integer'],
            'festival_workflow_step_id' => ['required', 'integer'],
            'code' => ['required', 'alpha_dash:ascii', 'max:100'],
            'type' => ['required', Rule::enum(FestivalRequirementType::class)],
            'subject_scope' => ['required', Rule::enum(FestivalFieldScope::class)],
            'input_type' => ['required', Rule::enum(FestivalRequirementInputType::class)],
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'options' => ['sometimes', 'array'],
            'options.*.value' => ['required', 'string', 'max:100', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'options.*.price_cents' => ['nullable', 'integer', 'min:0'],
            'pricing_mode' => ['required', Rule::in(['none', 'flat_when_true', 'per_unit', 'option_prices'])],
            'price_amount_cents' => ['nullable', 'integer', 'min:0'],
            'option_prices' => ['sometimes', 'array'],
            'option_prices.*' => ['integer', 'min:0'],
            'stage' => ['required', Rule::in(['qualification', 'final'])],
            'due_at' => ['nullable', 'date'],
            'allowed_extensions' => ['sometimes', 'array'],
            'allowed_extensions.*' => ['string', 'max:20', 'regex:/^[a-zA-Z0-9]+$/'],
            'allowed_mime_types' => ['sometimes', 'array'],
            'allowed_mime_types.*' => ['string', 'max:150'],
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:102400'],
            'min_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'max_duration_seconds' => ['nullable', 'integer', 'gte:min_duration_seconds'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
