<?php

namespace App\Actions\Festivals;

use App\Enums\AccountStatus;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTeamMemberType;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalParticipant;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Support\Festivals\FestivalRuleRegistry;
use App\Support\Festivals\FestivalSaasAccess;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentGatewayException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreFestivalEntry
{
    public function __construct(
        private readonly FestivalRuleRegistry $rules,
        private readonly FestivalSaasAccess $access,
        private readonly ReconcileFestivalPaymentAttempt $reconcile,
        private readonly FestivalActivityRecorder $activity,
    ) {}

    public function eligible(FestivalEntry $entry): bool
    {
        if ($entry->status !== FestivalEntryStatus::Withdrawn) {
            return false;
        }

        $entry->loadMissing(['account', 'portalUser', 'edition.purchase', 'category', 'participants', 'steps', 'charges']);

        return $this->canRestore($entry, $this->attempts($entry)->get());
    }

    public function execute(FestivalEntry $entry, FestivalPortalUser $actor): FestivalEntry
    {
        $entry = FestivalEntry::query()
            ->with(['account', 'portalUser', 'edition.purchase', 'category', 'participants', 'steps', 'charges'])
            ->whereKey($entry->id)
            ->where('account_id', $actor->account_id)
            ->where('festival_portal_user_id', $actor->id)
            ->firstOrFail();
        $this->access->assertEditionWritable($entry->edition);
        $attempts = $this->attempts($entry)->get();
        $this->assertRestorable($entry, $attempts);

        foreach ($attempts as $attempt) {
            if (! in_array($attempt->status, [FestivalPaymentStatus::Pending, FestivalPaymentStatus::Expired], true)) {
                continue;
            }

            try {
                $confirmed = $this->reconcile->execute($attempt);
            } catch (PaymentGatewayException|InvalidPaymentCallbackException $exception) {
                report($exception);

                throw ValidationException::withMessages(['entry' => __('app.festival_entry_restore_payment_unresolved')]);
            }

            if (! $this->confirmedUnpaid($confirmed)) {
                throw ValidationException::withMessages(['entry' => __('app.festival_entry_restore_payment_unresolved')]);
            }
        }

        return DB::transaction(function () use ($entry, $actor): FestivalEntry {
            $edition = $entry->edition()->lockForUpdate()->firstOrFail();
            $edition->setRelation('purchase', $edition->purchase()->lockForUpdate()->first());
            $this->access->assertEditionWritable($edition);
            $lockedEntry = FestivalEntry::query()
                ->whereKey($entry->id)
                ->where('account_id', $actor->account_id)
                ->where('festival_portal_user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();
            $attempts = $this->attempts($lockedEntry)->lockForUpdate()->get();
            $charges = $lockedEntry->charges()->orderBy('id')->lockForUpdate()->get();
            $lockedEntry->setRelation('edition', $edition);
            $lockedEntry->setRelation('charges', $charges);
            $lockedEntry->setRelation('account', $lockedEntry->account()->lockForUpdate()->firstOrFail());
            $lockedEntry->setRelation('portalUser', $lockedEntry->portalUser()->lockForUpdate()->firstOrFail());
            $lockedEntry->setRelation('category', $lockedEntry->category()->lockForUpdate()->firstOrFail());
            $lockedEntry->setRelation('participants', $lockedEntry->participants()->lockForUpdate()->get());
            $lockedEntry->setRelation('steps', $lockedEntry->steps()->lockForUpdate()->get());
            $this->assertRestorable($lockedEntry, $attempts);

            if ($attempts->contains(fn (FestivalPaymentAttempt $attempt): bool => ! $this->confirmedUnpaid($attempt))
                || $charges->contains('status', FestivalChargeStatus::PaymentPending)) {
                throw ValidationException::withMessages(['entry' => __('app.festival_entry_restore_payment_unresolved')]);
            }

            $lockedEntry->forceFill([
                'status' => FestivalEntryStatus::Draft,
                'withdrawn_at' => null,
            ])->save();
            $this->activity->record($lockedEntry, 'entry.restored', $edition, $actor);

            return $lockedEntry;
        }, 3);
    }

    /** @param Collection<int, FestivalPaymentAttempt> $attempts */
    private function assertRestorable(FestivalEntry $entry, Collection $attempts): void
    {
        if (! $this->canRestore($entry, $attempts)) {
            throw ValidationException::withMessages(['entry' => __('app.festival_entry_restore_unavailable')]);
        }
    }

    /** @param Collection<int, FestivalPaymentAttempt> $attempts */
    private function canRestore(FestivalEntry $entry, Collection $attempts): bool
    {
        if ($entry->status !== FestivalEntryStatus::Withdrawn
            || $entry->submitted_at !== null
            || $entry->reviewed_at !== null
            || $entry->reviewed_by !== null
            || $entry->accepted_at !== null
            || $entry->registration_completed_at !== null
            || $entry->rejected_at !== null
            || ! $entry->account->enable_festivals
            || $entry->account->status !== AccountStatus::Active
            || $entry->account->isReadOnlyDemo()
            || ! $entry->portalUser->is_active
            || $entry->portalUser->role !== FestivalPortalRole::Registrant
            || $entry->portalUser->account_id !== $entry->account_id
            || $entry->edition->account_id !== $entry->account_id
            || $this->access->editionIsReadOnly($entry->edition)
            || ! $entry->edition->registrationIsOpen()
            || ! $entry->category->is_active
            || $entry->category->account_id !== $entry->account_id
            || $entry->category->festival_edition_id !== $entry->festival_edition_id
            || $entry->category->applicationCapacityReached()) {
            return false;
        }

        if ($entry->steps->contains(fn (FestivalEntryStep $step): bool => $step->account_id !== $entry->account_id
            || $step->status !== FestivalEntryStepStatus::Draft
            || $step->submitted_at !== null
            || $step->reviewed_at !== null
            || $step->reviewed_by !== null)
            || $entry->participants->contains(fn (FestivalParticipant $participant): bool => $participant->account_id !== $entry->account_id
                || $participant->festival_portal_user_id !== $entry->festival_portal_user_id
                || $participant->pivot->account_id !== $entry->account_id
                || $participant->member_type !== FestivalTeamMemberType::Performer
                || $participant->archived_at !== null
                || $participant->date_of_birth === null)) {
            return false;
        }

        if ($entry->charges->contains(fn (FestivalCharge $charge): bool => $charge->account_id !== $entry->account_id
            || $charge->paid_at !== null
            || $charge->refunded_at !== null
            || in_array($charge->status, [FestivalChargeStatus::Paid, FestivalChargeStatus::PaidRequiresRefund, FestivalChargeStatus::Refunded], true))
            || $attempts->contains(fn (FestivalPaymentAttempt $attempt): bool => $attempt->account_id !== $entry->account_id
                || $attempt->status === FestivalPaymentStatus::Paid
                || $attempt->paid_at !== null
                || $attempt->gateway_status === 'reversed'
                || (in_array($attempt->status, [FestivalPaymentStatus::Pending, FestivalPaymentStatus::Expired], true) && $attempt->provider !== 'monopay'))) {
            return false;
        }

        try {
            $this->rules->validateEntry($entry->edition, $entry->category, $entry->participants, true, now(), false);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    private function confirmedUnpaid(FestivalPaymentAttempt $attempt): bool
    {
        return in_array($attempt->status, [FestivalPaymentStatus::Failed, FestivalPaymentStatus::Cancelled, FestivalPaymentStatus::Expired], true)
            && $attempt->paid_at === null
            && ($attempt->provider !== 'monopay' || in_array($attempt->gateway_status, ['failure', 'expired', 'cancelled'], true));
    }

    /** @return Builder<FestivalPaymentAttempt> */
    private function attempts(FestivalEntry $entry): Builder
    {
        return FestivalPaymentAttempt::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('charge', fn (Builder $charges) => $charges->where('festival_entry_id', $entry->id))
                ->orWhereHas('allocations.charge', fn (Builder $charges) => $charges->where('festival_entry_id', $entry->id)))
            ->orderBy('id');
    }
}
