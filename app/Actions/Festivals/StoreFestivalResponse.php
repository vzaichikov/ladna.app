<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSubmission;
use App\Support\Festivals\FestivalEntryWorkflowState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        $this->workflowState->assertMutable($requirement->entry, $requirement->entryStep);
        $value = $this->validatedValue($requirement, $value);

        $submission = DB::transaction(function () use ($requirement, $portalUser, $value): FestivalSubmission {
            $locked = FestivalEntryRequirement::query()->with(['definition', 'entry.edition', 'entry.steps.workflowStep', 'entryStep.workflowStep'])->whereKey($requirement->id)->lockForUpdate()->firstOrFail();
            $this->workflowState->assertMutable($locked->entry, $locked->entryStep);
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
                'value_json' => ['value' => $value],
                'status' => 'submitted',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_notes' => null,
            ]);
            $locked->forceFill(['status' => FestivalRequirementStatus::Submitted, 'reviewed_at' => null, 'reviewed_by' => null, 'review_notes' => null])->save();
            $this->activity->record($submission, 'response.saved', $locked->entry->edition, $portalUser);

            return $submission;
        }, 3);

        $this->reprice->execute($requirement, $submission);

        return $submission;
    }

    private function validatedValue(FestivalEntryRequirement $requirement, mixed $value): mixed
    {
        $type = $requirement->definition->input_type;
        $options = collect($requirement->definition->options ?? [])->pluck('value')->map(fn ($option): string => (string) $option)->all();
        $rules = match ($type) {
            FestivalRequirementInputType::ShortText => ['nullable', 'string', 'max:255'],
            FestivalRequirementInputType::LongText => ['nullable', 'string', 'max:10000'],
            FestivalRequirementInputType::Integer => ['nullable', 'integer', 'min:0'],
            FestivalRequirementInputType::Boolean => ['nullable', 'boolean'],
            FestivalRequirementInputType::Url => ['nullable', 'url:http,https', 'max:2048'],
            FestivalRequirementInputType::SingleSelect => ['nullable', 'string', 'in:'.implode(',', $options)],
            FestivalRequirementInputType::MultiSelect => ['nullable', 'array'],
            FestivalRequirementInputType::File => [],
        };
        if ($requirement->definition->is_required) {
            $rules[0] = 'required';
        }
        $validated = Validator::make(['value' => $value], ['value' => $rules])->validate();

        if ($type === FestivalRequirementInputType::MultiSelect) {
            Validator::make($validated, ['value.*' => ['string', 'in:'.implode(',', $options)]])->validate();
        }

        return $validated['value'] ?? null;
    }
}
