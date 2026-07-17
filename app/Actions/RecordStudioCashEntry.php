<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use App\Support\ActorSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

class RecordStudioCashEntry
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    public function execute(
        Account $account,
        ?Location $location,
        string $direction,
        int $amountCents,
        CarbonInterface $occurredAt,
        ?User $user,
        string $reason,
        ?string $purpose = null,
        ?StudioExpense $expense = null,
    ): StudioCashEntry {
        if ($location && $location->account_id !== $account->id) {
            abort(404);
        }

        if ($expense && ($expense->account_id !== $account->id || $expense->location_id !== $location?->id)) {
            abort(404);
        }

        $purpose ??= $direction === StudioCashEntry::DirectionIn
            ? StudioCashEntry::PurposeDeposit
            : StudioCashEntry::PurposeOwnerWithdrawal;

        validator(
            [
                'direction' => $direction,
                'amount_cents' => $amountCents,
                'purpose' => $purpose,
                'expense_id' => $expense?->id,
                'purpose_direction' => $purpose.':'.$direction,
            ],
            [
                'direction' => ['required', Rule::in([
                    StudioCashEntry::DirectionIn,
                    StudioCashEntry::DirectionOut,
                ])],
                'amount_cents' => ['required', 'integer', 'min:1'],
                'purpose' => ['required', Rule::in(StudioCashEntry::purposes())],
                'expense_id' => [Rule::requiredIf(in_array($purpose, [
                    StudioCashEntry::PurposeOperationalExpense,
                    StudioCashEntry::PurposeExpenseReversal,
                ], true))],
                'purpose_direction' => ['required', Rule::in([
                    StudioCashEntry::PurposeDeposit.':'.StudioCashEntry::DirectionIn,
                    StudioCashEntry::PurposeOwnerWithdrawal.':'.StudioCashEntry::DirectionOut,
                    StudioCashEntry::PurposeOperationalExpense.':'.StudioCashEntry::DirectionOut,
                    StudioCashEntry::PurposeExpenseReversal.':'.StudioCashEntry::DirectionIn,
                ])],
            ],
        )->validate();

        if ($expense && ! in_array($purpose, [
            StudioCashEntry::PurposeOperationalExpense,
            StudioCashEntry::PurposeExpenseReversal,
        ], true)) {
            abort(422);
        }

        return StudioCashEntry::query()->create([
            'account_id' => $account->id,
            'location_id' => $location?->id,
            'studio_expense_id' => $expense?->id,
            'direction' => $direction,
            'purpose' => $purpose,
            'amount_cents' => $amountCents,
            'currency' => $account->default_currency,
            'occurred_at' => $occurredAt,
            ...$this->actorSnapshot->capture($account, $user),
            'reason' => $reason,
        ]);
    }
}
