<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalPortalRole;
use App\Enums\FestivalRegistrantType;
use App\Enums\FestivalTeamMemberType;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use Illuminate\Validation\ValidationException;

class SyncFestivalProfileParticipant
{
    public function execute(FestivalPortalUser $portalUser, ?string $dateOfBirth): ?FestivalParticipant
    {
        if ($portalUser->role !== FestivalPortalRole::Registrant) {
            return null;
        }

        $participant = $portalUser->profileParticipant()->lockForUpdate()->first();

        if ($portalUser->registrant_type !== FestivalRegistrantType::AdultAthlete) {
            if ($participant?->isInUse()) {
                throw ValidationException::withMessages([
                    'registrant_type' => __('app.festival_registrant_type_locked'),
                ]);
            }

            $participant?->forceFill(['archived_at' => now()])->save();

            return null;
        }

        if (blank($dateOfBirth)) {
            return null;
        }

        if (! $participant) {
            $matches = $portalUser->participants()
                ->whereNull('is_profile_owner')
                ->whereNull('archived_at')
                ->where('first_name', $portalUser->first_name)
                ->where('last_name', $portalUser->last_name)
                ->where('patronymic', $portalUser->patronymic)
                ->whereDate('date_of_birth', $dateOfBirth)
                ->lockForUpdate()
                ->limit(2)
                ->get();
            $participant = $matches->count() === 1 ? $matches->first() : new FestivalParticipant;
        }

        $participant->fill([
            'account_id' => $portalUser->account_id,
            'festival_portal_user_id' => $portalUser->id,
            'is_profile_owner' => true,
            'member_type' => FestivalTeamMemberType::Performer,
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'patronymic' => $portalUser->patronymic,
            'date_of_birth' => $dateOfBirth,
            'archived_at' => null,
        ])->save();

        return $participant;
    }
}
