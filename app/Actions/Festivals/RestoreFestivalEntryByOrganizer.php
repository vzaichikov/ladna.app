<?php

namespace App\Actions\Festivals;

use App\Enums\AccountStatus;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalTeamMemberType;
use App\Enums\FestivalWorkflowStepType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\User;
use App\Support\Festivals\FestivalParticipantLimits;
use App\Support\Festivals\FestivalRuleRegistry;
use App\Support\Festivals\FestivalSaasAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RestoreFestivalEntryByOrganizer
{
    public function __construct(
        private readonly FestivalSaasAccess $access,
        private readonly FestivalRuleRegistry $rules,
        private readonly FestivalParticipantLimits $participantLimits,
        private readonly ReserveFestivalEntryTrack $reserveTrack,
        private readonly FestivalActivityRecorder $activity,
    ) {}

    public function execute(FestivalEntry $entry, User $actor, string $reason): FestivalEntry
    {
        return DB::transaction(function () use ($entry, $actor, $reason): FestivalEntry {
            FestivalEdition::query()->whereKey($entry->festival_edition_id)->lockForUpdate()->firstOrFail();
            $purchase = FestivalEditionPurchase::query()->with('package')
                ->where('festival_edition_id', $entry->festival_edition_id)->lockForUpdate()->first();
            $entry = FestivalEntry::query()->with(['account', 'portalUser', 'edition'])
                ->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manageFestivalRegistrations', $entry->account);
            $this->access->assertEditionWritable($entry->edition->setRelation('purchase', $purchase));
            abort_unless($entry->status === FestivalEntryStatus::Withdrawn, 409);
            if (blank($reason)) {
                throw ValidationException::withMessages(['reason' => __('validation.required', ['attribute' => __('app.reason')])]);
            }

            $category = $entry->category()->where('account_id', $entry->account_id)
                ->where('festival_edition_id', $entry->festival_edition_id)->lockForUpdate()->firstOrFail();
            $entry->setRelation('category', $category);
            $entry->setRelation('participants', $entry->participants()->lockForUpdate()->get());
            $entry->setRelation('steps', $entry->steps()->with('workflowStep')->orderBy('id')->lockForUpdate()->get());

            if (! $entry->account->enable_festivals || $entry->account->status !== AccountStatus::Active
                || $entry->account->isReadOnlyDemo() || ! $category->is_active
                || $entry->edition->account_id !== $entry->account_id
                || ! in_array($entry->edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true)
                || $entry->portalUser->account_id !== $entry->account_id
                || ! $entry->portalUser->is_active || $entry->portalUser->role !== FestivalPortalRole::Registrant
                || $entry->rejected_at !== null
                || $entry->participants->contains(fn ($participant): bool => $participant->account_id !== $entry->account_id
                    || $participant->festival_portal_user_id !== $entry->festival_portal_user_id
                    || $participant->pivot->account_id !== $entry->account_id
                    || $participant->member_type !== FestivalTeamMemberType::Performer
                    || $participant->archived_at !== null || $participant->date_of_birth === null)) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_organizer_restore_unavailable')]);
            }

            $this->rules->validateEntry($entry->edition, $category, $entry->participants, false, $entry->submitted_at ?? now(), (bool) $entry->submitted_at);
            $status = $this->restoredStatus($entry);
            if ($status !== FestivalEntryStatus::Draft) {
                $this->participantLimits->assertAvailable($entry, $purchase);
            }
            if (in_array($status, [FestivalEntryStatus::Accepted, FestivalEntryStatus::ChangesPending], true)
                && $category->applicationCapacityReached($category->capacityOccupyingEntries()->lockForUpdate()->get(['id'])->count())) {
                throw ValidationException::withMessages(['festival_application' => __('app.festival_category_full')]);
            }
            foreach ($entry->steps as $step) {
                if ($step->submitted_at !== null || in_array($step->status, [FestivalEntryStepStatus::Submitted, FestivalEntryStepStatus::Approved], true)) {
                    $this->reserveTrack->execute($entry, $step);
                }
            }
            $entry->forceFill(['status' => $status, 'withdrawn_at' => null])->save();
            $this->activity->record($entry, 'entry.restored', $entry->edition, $actor, [
                'status' => $status->value,
                'reason' => trim($reason),
            ]);

            return $entry;
        }, 3);
    }

    private function restoredStatus(FestivalEntry $entry): FestivalEntryStatus
    {
        $withdrawal = $entry->activityLogs()->where('action', 'entry.withdrawn')->latest('id')->first();
        $previous = FestivalEntryStatus::tryFrom((string) data_get($withdrawal?->payload, 'from_status'));
        if ($previous !== null && ! in_array($previous, [FestivalEntryStatus::Withdrawn, FestivalEntryStatus::Rejected], true)) {
            return $previous;
        }
        if ($entry->accepted_at !== null || $entry->registration_completed_at !== null) {
            return FestivalEntryStatus::ChangesPending;
        }
        if ($entry->submitted_at === null) {
            return FestivalEntryStatus::Draft;
        }
        if ($entry->qualification_status === FestivalQualificationStatus::Passed
            || $entry->steps->contains(fn (FestivalEntryStep $step): bool => $step->workflowStep->type !== FestivalWorkflowStepType::Application
                && $step->status !== FestivalEntryStepStatus::Draft)) {
            return FestivalEntryStatus::UnderReview;
        }

        return FestivalEntryStatus::Submitted;
    }
}
