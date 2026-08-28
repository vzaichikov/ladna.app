<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalTeamMemberType;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSubmission;
use App\Support\Festivals\FestivalEntryWorkflowState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StoreFestivalResponse
{
    public function __construct(
        private readonly FestivalEntryWorkflowState $workflowState,
        private readonly RepriceFestivalResponse $reprice,
        private readonly FestivalActivityRecorder $activity,
    ) {}

    public function execute(FestivalEntryRequirement $requirement, FestivalPortalUser $portalUser, mixed $value): FestivalSubmission
    {
        $requirement->load(['definition', 'entry.edition', 'entry.steps.workflowStep', 'entryStep.workflowStep']);
        abort_unless($requirement->account_id === $portalUser->account_id && $requirement->entry->festival_portal_user_id === $portalUser->id, 404);
        abort_unless($requirement->definition->input_type !== FestivalRequirementInputType::File, 422);
        $this->workflowState->assertRequirementMutable($requirement);

        $submission = DB::transaction(function () use ($requirement, $portalUser, $value): FestivalSubmission {
            $locked = FestivalEntryRequirement::query()->with(['definition', 'entry.edition', 'entry.steps.workflowStep', 'entryStep.workflowStep'])->whereKey($requirement->id)->lockForUpdate()->firstOrFail();
            $postConfirmationChange = $this->workflowState->assertRequirementMutable($locked);
            $value = $this->validatedValue($locked, $value);
            $storedValue = $locked->definition->input_type === FestivalRequirementInputType::HelperSelection
                ? $this->syncSelectedHelpers($locked, $portalUser, $value)
                : $value;
            $submission = $locked->submissions()->updateOrCreate([], [
                'account_id' => $portalUser->account_id,
                'festival_entry_id' => $locked->festival_entry_id,
                'festival_portal_user_id' => $portalUser->id,
                'disk' => null,
                'path' => null,
                'original_name' => null,
                'mime_type' => null,
                'size_bytes' => null,
                'duration_seconds' => null,
                'value_json' => ['value' => $storedValue],
                'status' => 'submitted',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_notes' => null,
            ]);
            $requirementStatus = $locked->definition->input_type === FestivalRequirementInputType::Agreement && $value === false
                ? FestivalRequirementStatus::Missing
                : FestivalRequirementStatus::Submitted;
            $locked->forceFill(['status' => $requirementStatus, 'reviewed_at' => null, 'reviewed_by' => null, 'review_notes' => null])->save();
            if ($postConfirmationChange) {
                $this->workflowState->markPostConfirmationChange($locked);
            }
            $this->activity->record($submission, 'response.saved', $locked->entry->edition, $portalUser);
            $this->reprice->execute($locked, $submission);

            return $submission;
        }, 3);

        return $submission;
    }

    private function validatedValue(FestivalEntryRequirement $requirement, mixed $value): mixed
    {
        $type = $requirement->definition->input_type;

        if ($type === FestivalRequirementInputType::HelperSelection) {
            return $this->validatedHelperSelection($value);
        }

        $options = collect($requirement->definition->options ?? [])->pluck('value')->map(fn ($option): string => (string) $option)->all();
        $rules = match ($type) {
            FestivalRequirementInputType::ShortText => ['nullable', 'string', 'max:255'],
            FestivalRequirementInputType::LongText => ['nullable', 'string', 'max:10000'],
            FestivalRequirementInputType::Integer => ['nullable', 'integer', 'min:0'],
            FestivalRequirementInputType::Boolean => ['nullable', 'boolean'],
            FestivalRequirementInputType::Agreement => ['present', 'boolean'],
            FestivalRequirementInputType::Url => ['nullable', 'url:http,https', 'max:2048'],
            FestivalRequirementInputType::SingleSelect => ['nullable', 'string', 'in:'.implode(',', $options)],
            FestivalRequirementInputType::MultiSelect => ['nullable', 'array'],
            FestivalRequirementInputType::File => [],
            FestivalRequirementInputType::HelperSelection => [],
        };
        if ($requirement->definition->is_required && $type !== FestivalRequirementInputType::Agreement) {
            $rules[0] = 'required';
        }
        $validated = Validator::make(['value' => $value], ['value' => $rules])->validate();

        if (in_array($type, [FestivalRequirementInputType::Boolean, FestivalRequirementInputType::Agreement], true)) {
            $validated['value'] = filter_var($validated['value'], FILTER_VALIDATE_BOOL);
        }

        if ($type === FestivalRequirementInputType::MultiSelect) {
            Validator::make($validated, ['value.*' => ['string', 'in:'.implode(',', $options)]])->validate();
        }

        if ($type === FestivalRequirementInputType::Url && filled($validated['value'] ?? null)) {
            $this->validateUrlHost($requirement, (string) $validated['value']);
        }

        return $validated['value'] ?? null;
    }

    /** @return array{enabled: bool, helper_ids: array<int, int>} */
    private function validatedHelperSelection(mixed $value): array
    {
        $enabled = is_array($value)
            ? filter_var($value['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;
        $helperIdsRules = $enabled === true
            ? ['required', 'array', 'min:1']
            : ['nullable', 'array'];
        $validated = Validator::make(['value' => $value], [
            'value' => ['required', 'array:enabled,helper_ids'],
            'value.enabled' => ['required', 'boolean'],
            'value.helper_ids' => $helperIdsRules,
            'value.helper_ids.*' => ['required', 'integer', 'min:1', 'distinct:strict'],
        ])->validate();
        $enabled = filter_var($validated['value']['enabled'], FILTER_VALIDATE_BOOL);

        return [
            'enabled' => $enabled,
            'helper_ids' => $enabled
                ? collect($validated['value']['helper_ids'])
                    ->map(fn (mixed $id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @param  array{enabled: bool, helper_ids: array<int, int>}  $value
     * @return array{enabled: bool}
     */
    private function syncSelectedHelpers(FestivalEntryRequirement $requirement, FestivalPortalUser $portalUser, array $value): array
    {
        $helperIds = $value['enabled'] ? $value['helper_ids'] : [];
        $helpers = FestivalParticipant::query()
            ->where('account_id', $portalUser->account_id)
            ->where('festival_portal_user_id', $portalUser->id)
            ->where('member_type', FestivalTeamMemberType::Helper->value)
            ->whereNull('archived_at')
            ->whereKey($helperIds)
            ->lockForUpdate()
            ->get();

        if ($helpers->count() !== count($helperIds)) {
            throw ValidationException::withMessages([
                'value.helper_ids' => __('app.festival_helper_invalid'),
            ]);
        }

        $sync = collect($helperIds)->mapWithKeys(fn (int $helperId, int $index): array => [
            $helperId => ['sort_order' => $index],
        ])->all();
        $requirement->selectedHelpers()->sync($sync);

        return ['enabled' => $value['enabled']];
    }

    private function validateUrlHost(FestivalEntryRequirement $requirement, string $value): void
    {
        $allowedHosts = collect(data_get($requirement->definition->validation, 'allowed_hosts', []))
            ->map(fn (mixed $host): string => mb_strtolower(trim((string) $host, '.')))
            ->filter();

        if ($allowedHosts->isEmpty()) {
            return;
        }

        $host = mb_strtolower((string) parse_url($value, PHP_URL_HOST));
        $allowed = $allowedHosts->contains(fn (string $candidate): bool => $host === $candidate || str_ends_with($host, '.'.$candidate));

        if (! $allowed) {
            throw ValidationException::withMessages(['value' => __('validation.url')]);
        }
    }
}
