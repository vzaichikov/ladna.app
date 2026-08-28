<?php

namespace App\Actions\Festivals;

use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaveFestivalParticipant
{
    /** @param array<string, mixed> $input */
    public function execute(
        FestivalPortalUser $portalUser,
        FestivalParticipant $participant,
        array $input,
        ?UploadedFile $photo = null,
    ): FestivalParticipant {
        $newPath = null;
        $oldPath = null;

        try {
            $newPath = $photo?->store(
                "festival-participant-photos/{$portalUser->account_id}/{$portalUser->id}",
                'local',
            );

            $participant = DB::transaction(function () use ($portalUser, $participant, $input, $newPath, &$oldPath): FestivalParticipant {
                $lockedParticipant = $participant->exists
                    ? FestivalParticipant::query()
                        ->whereKey($participant->id)
                        ->where('account_id', $portalUser->account_id)
                        ->where('festival_portal_user_id', $portalUser->id)
                        ->lockForUpdate()
                        ->firstOrFail()
                    : new FestivalParticipant;

                abort_if($lockedParticipant->is_profile_owner, 409);

                $memberTypeChanged = $lockedParticipant->exists
                    && $lockedParticipant->member_type?->value !== $input['member_type'];

                if ($memberTypeChanged && $lockedParticipant->isInUse()) {
                    throw ValidationException::withMessages([
                        'member_type' => __('app.festival_team_member_type_in_use'),
                    ]);
                }

                if (is_string($newPath) || (bool) ($input['remove_photo'] ?? false)) {
                    $oldPath = $lockedParticipant->photo_path;
                }

                $lockedParticipant->fill([
                    'account_id' => $portalUser->account_id,
                    'festival_portal_user_id' => $portalUser->id,
                    ...Arr::except($input, ['photo', 'remove_photo', 'fragment_context']),
                    'photo_path' => is_string($newPath)
                        ? $newPath
                        : ((bool) ($input['remove_photo'] ?? false) ? null : $lockedParticipant->photo_path),
                ])->save();

                return $lockedParticipant;
            }, 3);
        } catch (Throwable $throwable) {
            if (is_string($newPath)) {
                Storage::disk('local')->delete($newPath);
            }

            throw $throwable;
        }

        if (is_string($oldPath) && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return $participant->refresh()->load('portalUser');
    }
}
