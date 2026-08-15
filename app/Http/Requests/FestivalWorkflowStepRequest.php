<?php

namespace App\Http\Requests;

use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use App\Models\Account;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use App\Support\FestivalCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FestivalWorkflowStepRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $workflow = $this->route('festivalWorkflow');
        $step = $this->route('festivalWorkflowStep');

        if (! $workflow instanceof FestivalWorkflow) {
            return;
        }

        $this->merge([
            'code' => $step instanceof FestivalWorkflowStep
                ? $step->code
                : FestivalCodeGenerator::unique(
                    (string) $this->input('title'),
                    'step',
                    fn (string $candidate): bool => $workflow->steps()->where('code', $candidate)->exists(),
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
        $workflow = $this->route('festivalWorkflow');
        $step = $this->route('festivalWorkflowStep');

        return [
            'code' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('festival_workflow_steps')->where('festival_workflow_id', $workflow instanceof FestivalWorkflow ? $workflow->id : null)->ignore($step instanceof FestivalWorkflowStep ? $step->id : null)],
            'type' => ['required', Rule::enum(FestivalWorkflowStepType::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'review_mode' => ['required', Rule::enum(FestivalWorkflowReviewMode::class)],
            'review_effect' => ['required', Rule::enum(FestivalWorkflowReviewEffect::class)],
            'opens_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after:opens_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $workflow = $this->route('festivalWorkflow');
            $step = $this->route('festivalWorkflowStep');

            if (! $workflow instanceof FestivalWorkflow) {
                return;
            }

            $requestedSummary = $this->input('type') === FestivalWorkflowStepType::Summary->value;
            if ($step instanceof FestivalWorkflowStep
                && $step->type === FestivalWorkflowStepType::Summary
                && (! $requestedSummary || ! $this->boolean('is_active'))) {
                $validator->errors()->add('type', __('app.festival_summary_step_protected'));
            }

            if ($requestedSummary && $workflow->steps()
                ->where('type', FestivalWorkflowStepType::Summary->value)
                ->when($step instanceof FestivalWorkflowStep, fn ($query) => $query->whereKeyNot($step->id))
                ->exists()) {
                $validator->errors()->add('type', __('app.festival_summary_step_unique'));
            }
        }];
    }
}
