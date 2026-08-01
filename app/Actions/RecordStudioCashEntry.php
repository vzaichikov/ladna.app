<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseCorrection;
use App\Models\CustomerPurchaseRefund;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use App\Support\ActorSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        ?CustomerPurchaseRefund $refund = null,
        ?string $currency = null,
        ?CustomerPurchase $purchase = null,
        ?CustomerPurchaseCorrection $correction = null,
        ?string $sourceKey = null,
    ): StudioCashEntry {
        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => __('validation.required', ['attribute' => __('app.location')]),
            ]);
        }

        if ($location->account_id !== $account->id) {
            abort(404);
        }

        $purpose ??= $direction === StudioCashEntry::DirectionIn
            ? StudioCashEntry::PurposeDeposit
            : StudioCashEntry::PurposeOwnerWithdrawal;
        $currency = Str::upper($currency ?? $account->default_currency);
        $sourceKey ??= $this->defaultSourceKey($expense, $refund, $purchase, $correction, $purpose);

        $this->validateEntry(
            $account,
            $location,
            $direction,
            $amountCents,
            $purpose,
            $expense,
            $refund,
            $purchase,
            $correction,
            $currency,
            $sourceKey,
            $reason,
        );

        return DB::transaction(function () use ($account, $location, $direction, $amountCents, $occurredAt, $user, $reason, $purpose, $expense, $refund, $currency, $purchase, $correction, $sourceKey): StudioCashEntry {
            $lockedLocation = Location::query()
                ->whereBelongsTo($account)
                ->whereKey($location->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = [
                'account_id' => $account->id,
                'location_id' => $lockedLocation->id,
                'studio_expense_id' => $expense?->id,
                'customer_purchase_id' => $purchase?->id,
                'customer_purchase_correction_id' => $correction?->id,
                'customer_purchase_refund_id' => $refund?->id,
                'source_key' => $sourceKey,
                'direction' => $direction,
                'purpose' => $purpose,
                'amount_cents' => $amountCents,
                'currency' => $currency,
            ];
            $existingEntry = StudioCashEntry::query()
                ->where('source_key', $sourceKey)
                ->first();

            if ($existingEntry) {
                $this->ensureIdempotentMatch($existingEntry, $attributes);

                return $existingEntry;
            }

            $epoch = $this->activeOrLegacyEpoch($account, $occurredAt);

            return StudioCashEntry::query()->create([
                ...$attributes,
                'finance_epoch_id' => $epoch->id,
                'occurred_at' => $occurredAt,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);
        }, attempts: 5);
    }

    private function validateEntry(
        Account $account,
        Location $location,
        string $direction,
        int $amountCents,
        string $purpose,
        ?StudioExpense $expense,
        ?CustomerPurchaseRefund $refund,
        ?CustomerPurchase $purchase,
        ?CustomerPurchaseCorrection $correction,
        string $currency,
        string $sourceKey,
        string $reason,
    ): void {
        validator(
            [
                'direction' => $direction,
                'amount_cents' => $amountCents,
                'purpose' => $purpose,
                'purpose_direction' => $purpose.':'.$direction,
                'currency' => $currency,
                'source_key' => $sourceKey,
                'reason' => $reason,
            ],
            [
                'direction' => ['required', Rule::in([
                    StudioCashEntry::DirectionIn,
                    StudioCashEntry::DirectionOut,
                ])],
                'amount_cents' => ['required', 'integer', 'min:1'],
                'purpose' => ['required', Rule::in(StudioCashEntry::purposes())],
                'purpose_direction' => ['required', Rule::in([
                    StudioCashEntry::PurposeDeposit.':'.StudioCashEntry::DirectionIn,
                    StudioCashEntry::PurposeOwnerWithdrawal.':'.StudioCashEntry::DirectionOut,
                    StudioCashEntry::PurposeOperationalExpense.':'.StudioCashEntry::DirectionOut,
                    StudioCashEntry::PurposeExpenseReversal.':'.StudioCashEntry::DirectionIn,
                    StudioCashEntry::PurposePaymentRefund.':'.StudioCashEntry::DirectionOut,
                    StudioCashEntry::PurposeCustomerPayment.':'.StudioCashEntry::DirectionIn,
                    StudioCashEntry::PurposePaymentCorrectionReversal.':'.StudioCashEntry::DirectionOut,
                    StudioCashEntry::PurposePaymentCorrection.':'.StudioCashEntry::DirectionIn,
                ])],
                'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                'source_key' => ['required', 'string', 'max:191'],
                'reason' => ['required', 'string', 'max:2000'],
            ],
        )->validate();

        $relatedModels = array_filter([$expense, $refund, $purchase, $correction]);
        foreach ($relatedModels as $relatedModel) {
            if ($relatedModel->account_id !== $account->id) {
                abort(404);
            }
        }

        if ($expense) {
            $expenseCashLocationId = $expense->cash_location_id ?? $expense->location_id;
            if ($expenseCashLocationId !== $location->id) {
                abort(404);
            }
        }

        if ($refund && $refund->cash_location_id !== $location->id) {
            abort(404);
        }

        if ($purchase && ! $correction && $purchase->location_id !== $location->id) {
            abort(404);
        }

        if ($correction) {
            $expectedLocationId = $purpose === StudioCashEntry::PurposePaymentCorrectionReversal
                ? $correction->previous_location_id
                : $correction->new_location_id;

            if ($correction->customer_purchase_id !== $purchase?->id || $expectedLocationId !== $location->id) {
                abort(404);
            }
        }

        $validRelations = match ($purpose) {
            StudioCashEntry::PurposeOperationalExpense,
            StudioCashEntry::PurposeExpenseReversal => $expense !== null && $refund === null && $purchase === null && $correction === null,
            StudioCashEntry::PurposePaymentRefund => $expense === null && $refund !== null && $purchase === null && $correction === null,
            StudioCashEntry::PurposeCustomerPayment => $expense === null && $refund === null && $purchase !== null && $correction === null,
            StudioCashEntry::PurposePaymentCorrection,
            StudioCashEntry::PurposePaymentCorrectionReversal => $expense === null && $refund === null && $purchase !== null && $correction !== null,
            default => $expense === null && $refund === null && $purchase === null && $correction === null,
        };

        if (! $validRelations) {
            throw ValidationException::withMessages([
                'purpose' => __('validation.in', ['attribute' => 'purpose']),
            ]);
        }
    }

    private function defaultSourceKey(
        ?StudioExpense $expense,
        ?CustomerPurchaseRefund $refund,
        ?CustomerPurchase $purchase,
        ?CustomerPurchaseCorrection $correction,
        string $purpose,
    ): string {
        if ($expense) {
            return 'expense:'.$expense->id.':'.($purpose === StudioCashEntry::PurposeExpenseReversal ? 'reversal' : 'out');
        }

        if ($refund) {
            return 'refund:'.$refund->id;
        }

        if ($correction) {
            return 'correction:'.$correction->id.':'.($purpose === StudioCashEntry::PurposePaymentCorrectionReversal ? 'reversal' : 'corrected');
        }

        if ($purchase) {
            return 'purchase:'.$purchase->id.':cash-in';
        }

        return 'manual:'.Str::uuid();
    }

    private function activeOrLegacyEpoch(Account $account, CarbonInterface $occurredAt): FinanceEpoch
    {
        $epoch = $account->activeFinanceEpoch();

        if ($epoch) {
            if (! $epoch->is_legacy && $occurredAt->lessThan($epoch->starts_at)) {
                throw ValidationException::withMessages([
                    'occurred_at' => __('validation.after_or_equal', [
                        'attribute' => 'occurred at',
                        'date' => $epoch->starts_at->toDateTimeString(),
                    ]),
                ]);
            }

            return $epoch;
        }

        Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

        return $account->financeEpochs()->latest('starts_at')->first()
            ?? $account->financeEpochs()->create([
                'starts_at' => $occurredAt,
                'is_legacy' => true,
                'reason' => 'Automatically created legacy finance epoch.',
            ]);
    }

    /**
     * @param  array<string, int|string|null>  $attributes
     */
    private function ensureIdempotentMatch(StudioCashEntry $entry, array $attributes): void
    {
        foreach ($attributes as $attribute => $value) {
            if ((string) $entry->getAttribute($attribute) !== (string) $value) {
                throw ValidationException::withMessages([
                    'source_key' => __('validation.unique', ['attribute' => 'source key']),
                ]);
            }
        }
    }
}
