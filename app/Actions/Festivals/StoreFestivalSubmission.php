<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalSubmissionStatus;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSubmission;
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
        private readonly FestivalActivityRecorder $activity,
    ) {}

    public function execute(FestivalEntryRequirement $requirement, FestivalPortalUser $portalUser, UploadedFile $file): FestivalSubmission
    {
        $requirement->loadMissing(['entry.edition']);
        abort_unless($requirement->account_id === $portalUser->account_id && $requirement->entry->festival_portal_user_id === $portalUser->id, 404);
        $snapshot = $requirement->definition_snapshot;
        $mimeType = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));

        if ((int) ceil($file->getSize() / 1024) > (int) $snapshot['max_size_kb']) {
            throw ValidationException::withMessages(['file' => __('app.festival_file_too_large')]);
        }

        $allowedMimes = array_map('strtolower', (array) ($snapshot['allowed_mime_types'] ?? []));
        $allowedExtensions = array_map('strtolower', (array) ($snapshot['allowed_extensions'] ?? []));
        if (($allowedMimes !== [] && ! in_array(strtolower($mimeType), $allowedMimes, true))
            || ($allowedExtensions !== [] && ! in_array($extension, $allowedExtensions, true))) {
            throw ValidationException::withMessages(['file' => __('app.festival_file_type_invalid')]);
        }

        $duration = null;
        if (($snapshot['min_duration_seconds'] ?? null) !== null || ($snapshot['max_duration_seconds'] ?? null) !== null) {
            try {
                $duration = $this->durationProbe->seconds($file->getRealPath());
            } catch (Throwable) {
                throw ValidationException::withMessages(['file' => __('app.festival_file_duration_unreadable')]);
            }

            if (($snapshot['min_duration_seconds'] ?? null) !== null && $duration < $snapshot['min_duration_seconds']
                || ($snapshot['max_duration_seconds'] ?? null) !== null && $duration > $snapshot['max_duration_seconds']) {
                throw ValidationException::withMessages(['file' => __('app.festival_file_duration_invalid')]);
            }
        }

        $path = $file->store("festivals/{$portalUser->account_id}/entries/{$requirement->festival_entry_id}", 'local');

        try {
            return DB::transaction(function () use ($requirement, $portalUser, $file, $path, $mimeType, $duration): FestivalSubmission {
                $locked = FestivalEntryRequirement::query()->with('entry.edition')->whereKey($requirement->id)->lockForUpdate()->firstOrFail();
                $version = ((int) $locked->submissions()->max('version')) + 1;
                $locked->submissions()->where('status', FestivalSubmissionStatus::Submitted->value)->update(['status' => FestivalSubmissionStatus::Superseded->value]);
                $submission = $locked->submissions()->create([
                    'account_id' => $portalUser->account_id,
                    'festival_entry_id' => $locked->festival_entry_id,
                    'festival_portal_user_id' => $portalUser->id,
                    'version' => $version,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'size_bytes' => $file->getSize(),
                    'duration_seconds' => $duration,
                ]);
                $locked->forceFill(['status' => FestivalRequirementStatus::Submitted, 'reviewed_at' => null, 'reviewed_by' => null, 'review_notes' => null])->save();
                $this->activity->record($submission, 'submission.uploaded', $locked->entry->edition, $portalUser, ['version' => $version]);

                return $submission;
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }
}
