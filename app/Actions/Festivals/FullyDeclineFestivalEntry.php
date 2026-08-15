<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FullyDeclineFestivalEntry
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    public function execute(FestivalEntry $entry, User $reviewer, string $reason): FestivalEntry
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('validation.required')]);
        }
        if (mb_strlen($reason) > 5000) {
            throw ValidationException::withMessages(['reason' => __('validation.max.string', [
                'attribute' => __('app.festival_full_decline_reason'),
                'max' => 5000,
            ])]);
        }

        $notificationToken = (string) Str::uuid();

        return DB::transaction(function () use ($entry, $reviewer, $reason, $notificationToken): FestivalEntry {
            $entry = FestivalEntry::query()
                ->with(['account', 'edition', 'portalUser'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(! in_array($entry->status, [FestivalEntryStatus::Rejected, FestivalEntryStatus::Withdrawn], true), 409);

            $dependencies = $this->operationalDependencies($entry);
            if ($dependencies !== []) {
                throw ValidationException::withMessages([
                    'festival_application' => __('app.festival_full_decline_blocked', ['dependencies' => implode(', ', $dependencies)]),
                ]);
            }

            $declinedAt = now();
            $charges = FestivalCharge::query()
                ->where('festival_entry_id', $entry->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($charges as $charge) {
                if (in_array($charge->status, [FestivalChargeStatus::Pending, FestivalChargeStatus::PaymentPending, FestivalChargeStatus::Failed], true)) {
                    $charge->forceFill([
                        'status' => FestivalChargeStatus::Cancelled,
                        'cancelled_at' => $declinedAt,
                    ])->save();
                } elseif ($charge->status === FestivalChargeStatus::Paid) {
                    $charge->forceFill(['status' => FestivalChargeStatus::PaidRequiresRefund])->save();
                }
            }

            $entry->forceFill([
                'status' => FestivalEntryStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $declinedAt,
                'review_notes' => $reason,
                'accepted_at' => null,
                'registration_completed_at' => null,
                'rejected_at' => $declinedAt,
                'track_artist' => null,
                'track_title' => null,
                'normalized_track_key' => null,
                'track_reserved_at' => null,
            ])->save();

            $this->activity->record($entry, 'entry.reviewed', $entry->edition, $reviewer, [
                'status' => FestivalEntryStatus::Rejected->value,
                'review_notes' => $reason,
                'decision' => 'fully_declined',
            ]);
            $this->notifications->queueForEntry($entry, FestivalNotificationType::EntryReviewed, [
                'status' => FestivalEntryStatus::Rejected->value,
                'comment' => $reason,
            ], 'full-decline:'.$notificationToken);

            return $entry->refresh();
        }, 3);
    }

    /** @return list<string> */
    private function operationalDependencies(FestivalEntry $entry): array
    {
        $dependencies = [];

        if ($entry->scheduleSlots()->exists()) {
            $dependencies[] = __('app.festival_decline_dependency_schedule');
        }
        if ($entry->scoreSheets()->exists()) {
            $dependencies[] = __('app.festival_decline_dependency_scores');
        }
        if ($entry->penalties()->exists()) {
            $dependencies[] = __('app.festival_decline_dependency_penalties');
        }
        if ($entry->result()->exists()) {
            $dependencies[] = __('app.festival_decline_dependency_results');
        }

        $battleMatchExists = FestivalBattleMatch::query()
            ->where('account_id', $entry->account_id)
            ->where('festival_edition_id', $entry->festival_edition_id)
            ->where(fn ($query) => $query
                ->where('entry_a_id', $entry->id)
                ->orWhere('entry_b_id', $entry->id)
                ->orWhere('winner_entry_id', $entry->id))
            ->exists();
        $battleVoteExists = FestivalBattleJudgeVote::query()
            ->where('account_id', $entry->account_id)
            ->where('festival_edition_id', $entry->festival_edition_id)
            ->where('selected_entry_id', $entry->id)
            ->exists();

        if ($battleMatchExists || $battleVoteExists) {
            $dependencies[] = __('app.festival_decline_dependency_battles');
        }

        return $dependencies;
    }
}
