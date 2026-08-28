<?php

namespace App\Actions\Festivals;

use App\Models\FestivalPortalUser;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SaveFestivalProfilePhoto
{
    /** @param Closure(FestivalPortalUser): void $saveProfile */
    public function execute(
        FestivalPortalUser $portalUser,
        ?UploadedFile $photo,
        bool $removePhoto,
        Closure $saveProfile,
    ): FestivalPortalUser {
        $newPath = null;
        $oldPath = null;

        try {
            $newPath = $photo?->store(
                "festival-profile-photos/{$portalUser->account_id}/{$portalUser->id}",
                'local',
            );

            $portalUser = DB::transaction(function () use ($portalUser, $newPath, $removePhoto, $saveProfile, &$oldPath): FestivalPortalUser {
                $lockedPortalUser = FestivalPortalUser::query()
                    ->whereKey($portalUser->id)
                    ->where('account_id', $portalUser->account_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $saveProfile($lockedPortalUser);

                if (is_string($newPath) || $removePhoto) {
                    $oldPath = $lockedPortalUser->avatar_path;
                    $lockedPortalUser->forceFill([
                        'avatar_path' => is_string($newPath) ? $newPath : null,
                    ])->save();
                }

                return $lockedPortalUser;
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

        return $portalUser->refresh();
    }
}
