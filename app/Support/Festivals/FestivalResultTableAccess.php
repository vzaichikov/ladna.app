<?php

namespace App\Support\Festivals;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\User;

class FestivalResultTableAccess
{
    public function staffAssignment(?User $user, FestivalEdition $edition): ?FestivalJudgeAssignment
    {
        if (! $user) {
            return null;
        }

        return FestivalJudgeAssignment::query()
            ->with('categories')
            ->where('festival_edition_id', $edition->id)
            ->where('user_id', $user->id)
            ->where('is_head_judge', true)
            ->where('is_active', true)
            ->first();
    }

    public function portalAssignment(FestivalPortalUser $portalUser, FestivalEdition $edition): ?FestivalJudgeAssignment
    {
        if (! $portalUser->is_active || $portalUser->role !== FestivalPortalRole::Judge) {
            return null;
        }

        return FestivalJudgeAssignment::query()
            ->with('categories')
            ->where('festival_edition_id', $edition->id)
            ->where('festival_portal_user_id', $portalUser->id)
            ->where('is_head_judge', true)
            ->where('is_active', true)
            ->first();
    }

    public function canStaffView(?User $user, Account $account, FestivalEdition $edition): bool
    {
        return (bool) $user?->can('manageFestivals', $account) || $this->staffAssignment($user, $edition) !== null;
    }

    public function canStaffEdit(?User $user, Account $account, FestivalEdition $edition): bool
    {
        return $account->isOwnedBy($user) || $this->staffAssignment($user, $edition) !== null;
    }

    public function categoryAllowed(?FestivalJudgeAssignment $assignment, FestivalCategory $category): bool
    {
        return $assignment === null || $assignment->categories->contains('id', $category->id);
    }
}
