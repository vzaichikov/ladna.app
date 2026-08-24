<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSubmission;
use App\Support\Festivals\FestivalEntryWorkflowState;
use App\Support\Festivals\FestivalMediaDuration;
use App\Support\Festivals\MediaDurationProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreFestivalSubmission
{
    public function __construct(
        private readonly MediaDurationProbe $durationProbe,
        private readonly FestivalMediaDuration $mediaDuration,
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalEntryWorkflowState $workflowState,
    ) {}

    public function execute(FestivalEntryRequirement $requirement, FestivalPortalUser $portalUser, UploadedFile $file): FestivalSubmission
    {
        $requirement->load(['definition', 'entry.category', 'entry.edition', 'entry.steps.workflowStep', 'entryStep.workflowStep']);
        abort_unless($requirement->account_id === $portalUser->account_id && $requirement->entry->festival_portal_user_id === $portalUser->id, 404);
        abort_unless($requirement->definition->input_type === FestivalRequirementInputType::File, 422);
        if ($requirement->entryStep) {
            $this->workflowState->assertRequirementMutable($requirement);
        } else {
            abort_unless($requirement->entry->steps->isEmpty(), 409);
        }
        $definition = $requirement->definition;
        $mimeType = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));

        if ((int) ceil($file->getSize() / 1024) > $definition->max_size_kb) {
            throw ValidationException::withMessages(['file' => __('app.festival_file_too_large')]);
        }

        $allowedMimes = array_map('strtolower', $definition->allowed_mime_types ?? []);
        $allowedExtensions = array_map('strtolower', $definition->allowed_extensions ?? []);
        if (($allowedMimes !== [] && ! in_array(strtolower($mimeType), $allowedMimes, true))
            || ($allowedExtensions !== [] && ! in_array($extension, $allowedExtensions, true))) {
            throw ValidationException::withMessages(['file' => __('app.festival_file_type_invalid')]);
        }

        [$minimumDuration, $maximumDuration] = $this->mediaDuration->bounds($definition, $requirement->entry->category);
        $duration = null;
        if ($minimumDuration !== null || $maximumDuration !== null) {
            try {
                $duration = $this->durationProbe->seconds($file->getRealPath());
            } catch (Throwable) {
                throw ValidationException::withMessages(['file' => __('app.festival_file_duration_unreadable')]);
            }

            if (($minimumDuration !== null && $duration < $minimumDuration)
                || ($maximumDuration !== null && $duration > $maximumDuration)) {
                throw ValidationException::withMessages([
                    'file' => $this->mediaDuration->invalidMessage($minimumDuration, $maximumDuration, $duration),
                ]);
            }
        }

        $path = $file->store("festivals/{$portalUser->account_id}/entries/{$requirement->festival_entry_id}", 'local');

        $previousPath = null;
        try {
            $submission = DB::transaction(function () use ($requirement, $portalUser, $file, $path, $mimeType, $duration, &$previousPath): FestivalSubmission {
                $locked = FestivalEntryRequirement::query()->with(['definition', 'entry.edition', 'entry.steps.workflowStep', 'entryStep.workflowStep'])->whereKey($requirement->id)->lockForUpdate()->firstOrFail();
                if ($locked->entryStep) {
                    $postConfirmationChange = $this->workflowState->assertRequirementMutable($locked);
                } else {
                    abort_unless($locked->entry->steps->isEmpty(), 409);
                    $postConfirmationChange = false;
                }
                $current = $locked->submissions()->lockForUpdate()->first();
                $previousPath = $current?->path;
                $submission = $locked->submissions()->updateOrCreate([], [
                    'account_id' => $portalUser->account_id,
                    'festival_entry_id' => $locked->festival_entry_id,
                    'festival_portal_user_id' => $portalUser->id,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'size_bytes' => $file->getSize(),
                    'duration_seconds' => $duration,
                    'value_json' => null,
                    'status' => 'submitted',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_notes' => null,
                ]);
                $locked->forceFill(['status' => FestivalRequirementStatus::Submitted, 'reviewed_at' => null, 'reviewed_by' => null, 'review_notes' => null])->save();
                if ($postConfirmationChange) {
                    $this->workflowState->markPostConfirmationChange($locked);
                }
                $this->activity->record($submission, 'submission.uploaded', $locked->entry->edition, $portalUser);

                return $submission;
            }, 3);

            if ($previousPath && $previousPath !== $path) {
                Storage::disk('local')->delete($previousPath);
            }

            return $submission;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }
}
