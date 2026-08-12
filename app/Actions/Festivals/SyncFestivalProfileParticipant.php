<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalPortalRole;
use App\Enums\FestivalRegistrantType;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;

class SyncFestivalProfileParticipant
{
    public function execute(FestivalPortalUser $portalUser, ?string $dateOfBirth): ?FestivalParticipant
    {
        if ($portalUser->role !== FestivalPortalRole::Registrant || $portalUser->registrant_type !== FestivalRegistrantType::AdultAthlete || blank($dateOfBirth)) {
            return null;
        }

        $participant = $portalUser->profileParticipant()->lockForUpdate()->first();

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
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'patronymic' => $portalUser->patronymic,
            'date_of_birth' => $dateOfBirth,
            'archived_at' => null,
        ])->save();

        return $participant;
    }
}
