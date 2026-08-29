<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalTeamMemberType;
use App\Models\FestivalEdition;
use App\Models\FestivalParticipant;
use Illuminate\Database\Eloquent\Builder;

class FestivalEntrancePassEligibility
{
    /** @return Builder<FestivalParticipant> */
    public function queryForEdition(FestivalEdition $edition): Builder
    {
        return FestivalParticipant::query()
            ->active()
            ->where('account_id', $edition->account_id)
            ->whereHas('portalUser', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->where('role', FestivalPortalRole::Registrant->value))
            ->where(function (Builder $query) use ($edition): void {
                $query
                    ->where(function (Builder $query) use ($edition): void {
                        $query
                            ->where('member_type', FestivalTeamMemberType::Performer->value)
                            ->whereHas('entries', fn (Builder $query): Builder => $query
                                ->where('festival_edition_id', $edition->id)
                                ->where('status', FestivalEntryStatus::Accepted->value));
                    })
                    ->orWhere(function (Builder $query) use ($edition): void {
                        $query
                            ->where('member_type', FestivalTeamMemberType::Helper->value)
                            ->whereHas('helperRequirements', fn (Builder $query): Builder => $query
                                ->where('status', FestivalRequirementStatus::Accepted->value)
                                ->whereHas('definition', fn (Builder $query): Builder => $query
                                    ->where('input_type', FestivalRequirementInputType::HelperSelection->value))
                                ->whereHas('entry', fn (Builder $query): Builder => $query
                                    ->where('festival_edition_id', $edition->id)
                                    ->where('status', FestivalEntryStatus::Accepted->value))
                                ->whereHas('latestSubmission', fn (Builder $query): Builder => $query
                                    ->where('value_json->value->enabled', true)));
                    });
            });
    }

    public function isEligible(FestivalEdition $edition, FestivalParticipant $participant): bool
    {
        return $this->queryForEdition($edition)->whereKey($participant->id)->exists();
    }
}
