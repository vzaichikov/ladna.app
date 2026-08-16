<?php

namespace App\Support\Festivals;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\User;
use App\Support\EventFestivalStaffAccess;

class FestivalWorkspaceAccess
{
    public function __construct(private readonly EventFestivalStaffAccess $staffAccess) {}

    /**
     * @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool, door_staff: bool, event_festival_staff: bool, timeline_operator: bool, stream_administrator: bool}
     */
    public function permissions(?User $user, Account $account, FestivalEdition $edition): array
    {
        $canManage = (bool) $user?->can('manageFestivals', $account);
        $canJudge = (bool) $user?->can('judgeFestivals', $account);
        $isEventFestivalStaff = $user instanceof User
            && $this->staffAccess->canAccessFestival($user, $account, $edition);
        $canManageSchedule = (bool) $user?->can('manageFestivalSchedule', $account);
        $canManageFinance = (bool) $user?->can('manageFestivalFinance', $account);
        $hasJudgeAssignment = ! $canManage && $canJudge && FestivalJudgeAssignment::query()
            ->where('festival_edition_id', $edition->id)
            ->where('user_id', $user?->id)
            ->where('is_active', true)
            ->exists();

        return [
            'manage' => $canManage,
            'registrations' => (bool) $user?->can('manageFestivalRegistrations', $account),
            'schedule' => $canManageSchedule,
            'finance' => $canManageFinance,
            'judging' => $canManage || $hasJudgeAssignment,
            'ticket_check_in' => (bool) ($user?->can('checkInFestivalTickets', $account)
                || $user?->can('doorStaff', $account)),
            'door_staff' => (bool) $user?->can('doorStaff', $account),
            'event_festival_staff' => $isEventFestivalStaff,
            'timeline_operator' => $canManageSchedule || $isEventFestivalStaff,
            'stream_administrator' => $canManageFinance || $isEventFestivalStaff,
        ];
    }

    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool, door_staff: bool, event_festival_staff: bool, timeline_operator: bool, stream_administrator: bool}  $permissions
     */
    public function canAccessWorkspace(array $permissions): bool
    {
        return collect($permissions)
            ->except(['event_festival_staff', 'timeline_operator', 'stream_administrator'])
            ->contains(true);
    }
}
