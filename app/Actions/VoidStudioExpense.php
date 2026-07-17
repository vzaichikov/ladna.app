<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use App\Support\ActorSnapshot;
use Illuminate\Support\Facades\DB;

class VoidStudioExpense
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly RecordStudioCashEntry $recordStudioCashEntry,
    ) {}

    public function execute(Account $account, StudioExpense $studioExpense, ?User $user, string $reason): StudioExpense
    {
        validator(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:3', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($account, $studioExpense, $user, $reason): StudioExpense {
            $lockedExpense = StudioExpense::query()
                ->with('location')
                ->whereBelongsTo($account)
                ->whereKey($studioExpense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedExpense->isVoided()) {
                return $lockedExpense;
            }

            $voidedAt = now();

            $lockedExpense->forceFill([
                'voided_at' => $voidedAt,
                'void_reason' => $reason,
                ...$this->actorSnapshot->prefixed($account, $user, 'voided_by_actor'),
            ])->save();

            if ($lockedExpense->payment_method === StudioExpense::PaymentMethodCashdesk) {
                $this->recordStudioCashEntry->execute(
                    $account,
                    $lockedExpense->location,
                    StudioCashEntry::DirectionIn,
                    (int) $lockedExpense->amount_cents,
                    $voidedAt,
                    $user,
                    $reason,
                    StudioCashEntry::PurposeExpenseReversal,
                    $lockedExpense,
                );
            }

            return $lockedExpense;
        }, 5);
    }
}
