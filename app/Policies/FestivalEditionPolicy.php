<?php

namespace App\Policies;

use App\Enums\StudioPermission;
use App\Models\FestivalEdition;
use App\Models\User;

class FestivalEditionPolicy
{
    public function view(User $user, FestivalEdition $edition): bool
    {
        $account = $edition->account;

        foreach ([
            StudioPermission::ManageFestivals,
            StudioPermission::ManageFestivalRegistrations,
            StudioPermission::ManageFestivalSchedule,
            StudioPermission::ManageFestivalFinance,
            StudioPermission::JudgeFestivals,
            StudioPermission::CheckInFestivalTickets,
            StudioPermission::DoorStaff,
        ] as $permission) {
            if ($account->userCan($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function update(User $user, FestivalEdition $edition): bool
    {
        return $edition->account->userCan($user, StudioPermission::ManageFestivals);
    }

    public function delete(User $user, FestivalEdition $edition): bool
    {
        return $this->update($user, $edition) && $edition->entries()->doesntExist();
    }
}
