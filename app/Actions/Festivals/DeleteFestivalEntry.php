<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPaymentStatus;
use App\Models\FestivalCharge;
use App\Models\FestivalEntry;
use App\Models\FestivalSubmission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteFestivalEntry
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    public function canDelete(FestivalEntry $entry): bool
    {
        $entry->loadMissing('charges.paymentAttempts');

        return ! $this->hasProtectedPaymentHistory($entry, $entry->charges);
    }

    public function execute(FestivalEntry $entry, User $actor): void
    {
        $files = DB::transaction(function () use ($entry, $actor): Collection {
            $entry = FestivalEntry::query()
                ->with('edition')
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            $charges = FestivalCharge::query()
                ->with('paymentAttempts')
                ->where('festival_entry_id', $entry->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($this->hasProtectedPaymentHistory($entry, $charges)) {
                throw ValidationException::withMessages([
                    'festival_application' => __('app.festival_application_delete_payment_history'),
                ]);
            }

            $submissions = FestivalSubmission::query()
                ->where('festival_entry_id', $entry->id)
                ->whereNotNull('path')
                ->where('path', '!=', '')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['disk', 'path']);
            $this->activity->record($entry, 'entry.deleted', $entry->edition, $actor, [
                'code' => $entry->code,
                'entry_name' => $entry->entry_name,
            ]);
            $entry->delete();

            return $submissions->map(fn (FestivalSubmission $submission): array => [
                'disk' => $submission->disk ?: 'local',
                'path' => $submission->path,
            ]);
        }, 3);

        $files->groupBy('disk')->each(function (Collection $diskFiles, string $disk): void {
            Storage::disk($disk)->delete($diskFiles->pluck('path')->all());
        });
    }

    /** @param Collection<int, FestivalCharge> $charges */
    private function hasProtectedPaymentHistory(FestivalEntry $entry, Collection $charges): bool
    {
        return $charges->contains(function (FestivalCharge $charge) use ($entry): bool {
            $hasPaidFact = $charge->paid_at !== null
                || in_array($charge->status, [
                    FestivalChargeStatus::Paid,
                    FestivalChargeStatus::PaidRequiresRefund,
                    FestivalChargeStatus::Refunded,
                ], true)
                || $charge->paymentAttempts->contains(fn ($attempt): bool => $attempt->paid_at !== null || $attempt->status === FestivalPaymentStatus::Paid);

            if ($hasPaidFact || $charge->paymentAttempts->isEmpty()) {
                return $hasPaidFact;
            }

            $wasDeclinedManually = $entry->status === FestivalEntryStatus::Draft
                && $charge->status === FestivalChargeStatus::Failed
                && $charge->approved_by !== null;

            return ! $wasDeclinedManually;
        });
    }
}
