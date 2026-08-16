<?php

namespace App\Support\Festivals;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\User;

class FestivalWorkspaceAccess
{
    /**
     * @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool, door_staff: bool}
     */
    public function permissions(?User $user, Account $account, FestivalEdition $edition): array
    {
        $canManage = (bool) $user?->can('manageFestivals', $account);
        $canJudge = (bool) $user?->can('judgeFestivals', $account);
        $hasJudgeAssignment = ! $canManage && $canJudge && FestivalJudgeAssignment::query()
            ->where('festival_edition_id', $edition->id)
            ->where('user_id', $user?->id)
            ->where('is_active', true)
            ->exists();

        return [
            'manage' => $canManage,
            'registrations' => (bool) $user?->can('manageFestivalRegistrations', $account),
            'schedule' => (bool) $user?->can('manageFestivalSchedule', $account),
            'finance' => (bool) $user?->can('manageFestivalFinance', $account),
            'judging' => $canManage || $hasJudgeAssignment,
            'ticket_check_in' => (bool) ($user?->can('checkInFestivalTickets', $account)
                || $user?->can('doorStaff', $account)),
            'door_staff' => (bool) $user?->can('doorStaff', $account),
        ];
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool, door_staff: bool}  $permissions
     */
    public function canAccessWorkspace(array $permissions): bool
    {
        return in_array(true, $permissions, true);
    }
}
