<?php

namespace App\Support;

use App\Enums\AccountRole;
use App\Enums\EventStatus;
use App\Enums\FestivalEditionStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\FestivalEdition;
use App\Models\User;

class EventFestivalStaffAccess
{
    public function isStaff(User $user, Account $account): bool
    {
        return $account->membershipFor($user)?->role === AccountRole::EventFestivalStaff;
    }

    public function canAccessEvent(User $user, Account $account, Event $event): bool
    {
        return $this->isStaff($user, $account)
            && $event->account_id === $account->id
            && $event->status === EventStatus::Published
            && $event->ends_at !== null
            && $event->ends_at->greaterThanOrEqualTo(now()->subDay());
    }

    public function canAccessFestival(User $user, Account $account, FestivalEdition $edition): bool
    {
        return $this->isStaff($user, $account)
            && $edition->account_id === $account->id
            && in_array($edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
            && $edition->ends_at !== null
            && $edition->ends_at->greaterThanOrEqualTo(now()->subDay());
    }
}
