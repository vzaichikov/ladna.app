<?php

namespace App\Http\Requests;

use App\Enums\FestivalFieldScope;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementType;
use App\Enums\FestivalWorkflowStepType;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalWorkflowStep;
use App\Support\FestivalCodeGenerator;
use App\Support\Festivals\FestivalRequirementDeadlineResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $list = fn (string $key): array => collect(preg_split('/[,\n]+/', (string) $this->input($key), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $prepared = [
            'options' => $options,
            'allowed_extensions' => $list('allowed_extensions_text'),
            'allowed_mime_types' => $list('allowed_mime_types_text'),
            'allowed_hosts' => collect($list('allowed_hosts_text'))
                ->map(fn (string $host): string => mb_strtolower(trim($host, ". \t\n\r\0\x0B")))
                ->filter()
                ->values()
                ->all(),
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
            'options.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'pricing_mode' => ['required', Rule::in(['none', 'flat_when_true', 'per_unit', 'option_prices'])],
            'price_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'due_reference' => ['nullable', Rule::in(FestivalRequirementDeadlineResolver::References), 'required_with:due_offset_days'],
            'due_offset_days' => ['nullable', 'integer', 'between:-366,366', 'required_with:due_reference'],
            'allow_post_confirmation_edits' => ['sometimes', 'boolean'],
            'editable_until_reference' => ['nullable', Rule::in(FestivalRequirementDeadlineResolver::References), 'required_if:allow_post_confirmation_edits,1'],
            'editable_until_offset_days' => ['nullable', 'integer', 'between:-366,366', 'required_if:allow_post_confirmation_edits,1'],
            'allowed_extensions' => ['sometimes', 'array'],
            'allowed_extensions.*' => ['string', 'max:20', 'regex:/^[a-zA-Z0-9]+$/'],
            'allowed_mime_types' => ['sometimes', 'array'],
            'allowed_mime_types.*' => ['string', 'max:150'],
            'allowed_hosts' => ['sometimes', 'array'],
            'allowed_hosts.*' => ['string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:102400'],
            'min_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'max_duration_seconds' => ['nullable', 'integer', 'gte:min_duration_seconds'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'show_in_media_report' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $edition = $this->route('festivalEdition');
            if (! $edition instanceof FestivalEdition) {
                return;
            }

            if (FestivalWorkflowStep::query()
                ->whereKey($this->integer('festival_workflow_step_id'))
                ->whereHas('workflow', fn ($query) => $query->where('festival_edition_id', $edition->id))
                ->where('type', FestivalWorkflowStepType::Summary->value)
                ->exists()) {
                $validator->errors()->add('festival_workflow_step_id', __('app.festival_summary_step_definitions_blocked'));
            }

            $referenceFields = ['due_reference'];
            if ($this->boolean('allow_post_confirmation_edits')) {
                $referenceFields[] = 'editable_until_reference';
            }

            foreach ($referenceFields as $field) {
                $reference = $this->input($field);
                if (is_string($reference)
                    && in_array($reference, FestivalRequirementDeadlineResolver::References, true)
                    && $edition->{$reference} === null) {
                    $validator->errors()->add($field, __('app.festival_deadline_reference_missing'));
                }
            }
        }];
    }
}
