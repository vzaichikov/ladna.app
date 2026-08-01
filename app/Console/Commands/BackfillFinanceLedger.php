<?php

namespace App\Console\Commands;

use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseCorrection;
use App\Models\CustomerPurchaseRefund;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

#[Signature('finance:backfill-ledger {--account= : Limit the backfill to one account ID}')]
#[Description('Create legacy finance epochs and idempotently backfill the append-only cash ledger')]
class BackfillFinanceLedger extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $accountId = $this->option('account');

        if ($accountId !== null && filter_var($accountId, FILTER_VALIDATE_INT) === false) {
            $this->error('The --account option must be an integer account ID.');

            return self::FAILURE;
        }

        $accounts = Account::query()
            ->when($accountId !== null, fn ($query) => $query->whereKey((int) $accountId))
            ->orderBy('id');
        $processedAccounts = 0;

        $accounts->lazyById()->each(function (Account $account) use (&$processedAccounts): void {
            $statistics = $this->backfillAccount($account);
            $processedAccounts++;
            $this->components->info(sprintf(
                '%s: legacy epoch %d, %d ledger entries created, %d existing entries linked, %d expenses normalized, %d rows skipped.',
                $account->name,
                $statistics['legacy_epoch_id'],
                $statistics['entries_created'],
                $statistics['entries_linked'],
                $statistics['expenses_normalized'],
                $statistics['skipped'],
            ));
        });

        if ($processedAccounts === 0) {
            $this->warn('No matching accounts were found.');

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{legacy_epoch_id: int, entries_created: int, entries_linked: int, expenses_normalized: int, skipped: int}
     */
    private function backfillAccount(Account $account): array
    {
        return DB::transaction(function () use ($account): array {
            $statistics = [
                'legacy_epoch_id' => 0,
                'entries_created' => 0,
                'entries_linked' => 0,
                'expenses_normalized' => 0,
                'skipped' => 0,
            ];
            $locations = Location::query()
                ->whereBelongsTo($account)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $legacyEpoch = $this->legacyEpoch($account);
            $statistics['legacy_epoch_id'] = $legacyEpoch->id;
            $epochs = $account->financeEpochs()->orderBy('starts_at')->orderBy('id')->get();

            $this->normalizeExpenses($account, $statistics);
            $this->linkExistingEntries($account, $legacyEpoch, $epochs, $statistics);
            $this->backfillPurchases($account, $locations, $legacyEpoch, $epochs, $statistics);
            $this->backfillExpenses($account, $locations, $legacyEpoch, $epochs, $statistics);
            $this->backfillRefunds($account, $locations, $legacyEpoch, $epochs, $statistics);

            return $statistics;
        }, attempts: 5);
    }

    private function legacyEpoch(Account $account): FinanceEpoch
    {
        $startsAt = $this->earliestFinanceTimestamp($account);
        $legacyEpoch = $account->financeEpochs()
            ->where('is_legacy', true)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();

        if ($legacyEpoch) {
            if ($legacyEpoch->starts_at->greaterThan($startsAt)) {
                $legacyEpoch->forceFill(['starts_at' => $startsAt])->saveQuietly();
            }

            return $legacyEpoch;
        }

        return $account->financeEpochs()->create([
            'starts_at' => $startsAt,
            'is_legacy' => true,
            'reason' => 'Legacy history imported before cashbox reconciliation.',
        ]);
    }

    private function earliestFinanceTimestamp(Account $account): CarbonImmutable
    {
        $timestamps = collect([
            StudioCashEntry::query()->whereBelongsTo($account)->min('occurred_at'),
            CustomerPurchase::query()
                ->whereBelongsTo($account)
                ->whereIn('payment_source', [
                    CustomerPurchase::SourceManualCashClassPass,
                    CustomerPurchase::SourceManualCashBooking,
                ])
                ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
                ->min('paid_at'),
            StudioExpense::query()->whereBelongsTo($account)->min('occurred_at'),
            CustomerPurchaseCorrection::query()->whereBelongsTo($account)->min('created_at'),
            CustomerPurchaseRefund::query()->whereBelongsTo($account)->min('refunded_at'),
            $account->created_at,
        ])->filter();
        $startsAt = $timestamps
            ->map(fn (mixed $timestamp): CarbonImmutable => CarbonImmutable::parse($timestamp))
            ->sortBy(fn (CarbonImmutable $timestamp): int => $timestamp->getTimestamp())
            ->first() ?? now()->toImmutable();

        return $startsAt;
    }

    /**
     * @param  array{legacy_epoch_id: int, entries_created: int, entries_linked: int, expenses_normalized: int, skipped: int}  $statistics
     */
    private function normalizeExpenses(Account $account, array &$statistics): void
    {
        StudioExpense::query()
            ->whereBelongsTo($account)
            ->orderBy('id')
            ->lazyById()
            ->each(function (StudioExpense $expense) use (&$statistics): void {
                $expenseLocationId = $expense->expense_location_id ?? $expense->location_id;
                $cashLocationId = $expense->payment_method === StudioExpense::PaymentMethodCashdesk
                    ? ($expense->cash_location_id ?? $expense->location_id)
                    : null;

                if ($expense->expense_location_id === $expenseLocationId
                    && $expense->cash_location_id === $cashLocationId) {
                    return;
                }

                $expense->forceFill([
                    'expense_location_id' => $expenseLocationId,
                    'cash_location_id' => $cashLocationId,
                ])->saveQuietly();
                $statistics['expenses_normalized']++;
            });
    }

    /**
     * @param  Collection<int, FinanceEpoch>  $epochs
     * @param  array{legacy_epoch_id: int, entries_created: int, entries_linked: int, expenses_normalized: int, skipped: int}  $statistics
     */
    private function linkExistingEntries(
        Account $account,
        FinanceEpoch $legacyEpoch,
        Collection $epochs,
        array &$statistics,
    ): void {
        StudioCashEntry::query()
            ->whereBelongsTo($account)
            ->where(function ($query): void {
                $query->whereNull('finance_epoch_id')->orWhereNull('source_key');
            })
            ->orderBy('id')
            ->lazyById()
            ->each(function (StudioCashEntry $entry) use ($legacyEpoch, $epochs, &$statistics): void {
                $sourceKey = $entry->source_key ?? $this->sourceKeyForExistingEntry($entry);
                $conflictingEntry = StudioCashEntry::query()
                    ->where('source_key', $sourceKey)
                    ->whereKeyNot($entry->id)
                    ->exists();

                if ($conflictingEntry) {
                    $sourceKey = 'legacy:cash-entry:'.$entry->id;
                }

                $entry->forceFill([
                    'finance_epoch_id' => $entry->finance_epoch_id
                        ?? $this->epochFor($epochs, $legacyEpoch, $entry->occurred_at)->id,
                    'source_key' => $sourceKey,
                ])->saveQuietly();
                $statistics['entries_linked']++;
            });
    }

    private function sourceKeyForExistingEntry(StudioCashEntry $entry): string
    {
        if ($entry->studio_expense_id) {
            return 'expense:'.$entry->studio_expense_id.':'.($entry->purpose === StudioCashEntry::PurposeExpenseReversal ? 'reversal' : 'out');
        }

        if ($entry->customer_purchase_refund_id) {
            return 'refund:'.$entry->customer_purchase_refund_id;
        }

        if ($entry->customer_purchase_correction_id) {
            return 'correction:'.$entry->customer_purchase_correction_id.':'.($entry->purpose === StudioCashEntry::PurposePaymentCorrectionReversal ? 'reversal' : 'corrected');
        }

        if ($entry->customer_purchase_id) {
            return 'purchase:'.$entry->customer_purchase_id.':cash-in';
        }

        return 'legacy:cash-entry:'.$entry->id;
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  Collection<int, FinanceEpoch>  $epochs
     * @param  array{legacy_epoch_id: int, entries_created: int, entries_linked: int, expenses_normalized: int, skipped: int}  $statistics
     */
    private function backfillPurchases(
        Account $account,
        Collection $locations,
        FinanceEpoch $legacyEpoch,
        Collection $epochs,
        array &$statistics,
    ): void {
        CustomerPurchase::query()
            ->with(['corrections' => fn ($query) => $query->orderBy('id')])
            ->whereBelongsTo($account)
            ->whereIn('payment_source', [
                CustomerPurchase::SourceManualCashClassPass,
                CustomerPurchase::SourceManualCashBooking,
            ])
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->orderBy('id')
            ->lazyById()
            ->each(function (CustomerPurchase $purchase) use ($locations, $legacyEpoch, $epochs, &$statistics): void {
                /** @var CustomerPurchaseCorrection|null $firstCorrection */
                $firstCorrection = $purchase->corrections->first();
                $initialLocationId = $firstCorrection?->previous_location_id ?? $purchase->location_id;
                $initialAmountCents = (int) ($firstCorrection?->previous_amount_cents ?? $purchase->amount_cents);
                $initialOccurredAt = $firstCorrection?->previous_paid_at ?? $purchase->effectiveOccurredAt();

                if (! $locations->has($initialLocationId) || $initialAmountCents <= 0 || ! $initialOccurredAt) {
                    $statistics['skipped']++;
                } else {
                    $this->createLedgerEntry([
                        'account_id' => $purchase->account_id,
                        'finance_epoch_id' => $this->epochFor($epochs, $legacyEpoch, $initialOccurredAt)->id,
                        'location_id' => $initialLocationId,
                        'customer_purchase_id' => $purchase->id,
                        'source_key' => 'purchase:'.$purchase->id.':cash-in',
                        'direction' => StudioCashEntry::DirectionIn,
                        'purpose' => StudioCashEntry::PurposeCustomerPayment,
                        'amount_cents' => $initialAmountCents,
                        'currency' => $purchase->currency,
                        'occurred_at' => $initialOccurredAt,
                        'reason' => 'Legacy cash payment backfill.',
                    ], $statistics);
                }

                foreach ($purchase->corrections as $correction) {
                    $occurredAt = $correction->created_at ?? $correction->new_paid_at ?? $purchase->effectiveOccurredAt();
                    $epoch = $this->epochFor($epochs, $legacyEpoch, $occurredAt);
                    $actor = $this->actorAttributes($correction);

                    if ($locations->has($correction->previous_location_id) && $correction->previous_amount_cents > 0) {
                        $this->createLedgerEntry([
                            'account_id' => $purchase->account_id,
                            'finance_epoch_id' => $epoch->id,
                            'location_id' => $correction->previous_location_id,
                            'customer_purchase_id' => $purchase->id,
                            'customer_purchase_correction_id' => $correction->id,
                            'source_key' => 'correction:'.$correction->id.':reversal',
                            'direction' => StudioCashEntry::DirectionOut,
                            'purpose' => StudioCashEntry::PurposePaymentCorrectionReversal,
                            'amount_cents' => $correction->previous_amount_cents,
                            'currency' => $purchase->currency,
                            'occurred_at' => $occurredAt,
                            ...$actor,
                            'reason' => $correction->reason,
                        ], $statistics);
                    } else {
                        $statistics['skipped']++;
                    }

                    if ($locations->has($correction->new_location_id) && $correction->new_amount_cents > 0) {
                        $this->createLedgerEntry([
                            'account_id' => $purchase->account_id,
                            'finance_epoch_id' => $epoch->id,
                            'location_id' => $correction->new_location_id,
                            'customer_purchase_id' => $purchase->id,
                            'customer_purchase_correction_id' => $correction->id,
                            'source_key' => 'correction:'.$correction->id.':corrected',
                            'direction' => StudioCashEntry::DirectionIn,
                            'purpose' => StudioCashEntry::PurposePaymentCorrection,
                            'amount_cents' => $correction->new_amount_cents,
                            'currency' => $purchase->currency,
                            'occurred_at' => $occurredAt,
                            ...$actor,
                            'reason' => $correction->reason,
                        ], $statistics);
                    } else {
                        $statistics['skipped']++;
                    }
                }
            });
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  Collection<int, FinanceEpoch>  $epochs
     * @param  array{legacy_epoch_id: int, entries_created: int, entries_linked: int, expenses_normalized: int, skipped: int}  $statistics
     */
    private function backfillExpenses(
        Account $account,
        Collection $locations,
        FinanceEpoch $legacyEpoch,
        Collection $epochs,
        array &$statistics,
    ): void {
        StudioExpense::query()
            ->whereBelongsTo($account)
            ->where('payment_method', StudioExpense::PaymentMethodCashdesk)
            ->orderBy('id')
            ->lazyById()
            ->each(function (StudioExpense $expense) use ($locations, $legacyEpoch, $epochs, &$statistics): void {
                $cashLocationId = $expense->cash_location_id ?? $expense->location_id;
                if (! $locations->has($cashLocationId)) {
                    $statistics['skipped']++;

                    return;
                }

                $this->createLedgerEntry([
                    'account_id' => $expense->account_id,
                    'finance_epoch_id' => $this->epochFor($epochs, $legacyEpoch, $expense->occurred_at)->id,
                    'location_id' => $cashLocationId,
                    'studio_expense_id' => $expense->id,
                    'source_key' => 'expense:'.$expense->id.':out',
                    'direction' => StudioCashEntry::DirectionOut,
                    'purpose' => StudioCashEntry::PurposeOperationalExpense,
                    'amount_cents' => $expense->amount_cents,
                    'currency' => $expense->currency,
                    'occurred_at' => $expense->occurred_at,
                    ...$this->actorAttributes($expense),
                    'reason' => $expense->reason,
                ], $statistics);

                if (! $expense->voided_at) {
                    return;
                }

                $this->createLedgerEntry([
                    'account_id' => $expense->account_id,
                    'finance_epoch_id' => $this->epochFor($epochs, $legacyEpoch, $expense->voided_at)->id,
                    'location_id' => $cashLocationId,
                    'studio_expense_id' => $expense->id,
                    'source_key' => 'expense:'.$expense->id.':reversal',
                    'direction' => StudioCashEntry::DirectionIn,
                    'purpose' => StudioCashEntry::PurposeExpenseReversal,
                    'amount_cents' => $expense->amount_cents,
                    'currency' => $expense->currency,
                    'occurred_at' => $expense->voided_at,
                    ...$this->prefixedActorAttributes($expense, 'voided_by_actor'),
                    'reason' => $expense->void_reason ?? $expense->reason,
                ], $statistics);
            });
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  Collection<int, FinanceEpoch>  $epochs
     * @param  array{legacy_epoch_id: int, entries_created: int, entries_linked: int, expenses_normalized: int, skipped: int}  $statistics
     */
    private function backfillRefunds(
        Account $account,
        Collection $locations,
        FinanceEpoch $legacyEpoch,
        Collection $epochs,
        array &$statistics,
    ): void {
        CustomerPurchaseRefund::query()
            ->whereBelongsTo($account)
            ->where('method', CustomerPurchaseRefund::MethodCash)
            ->orderBy('id')
            ->lazyById()
            ->each(function (CustomerPurchaseRefund $refund) use ($locations, $legacyEpoch, $epochs, &$statistics): void {
                if (! $locations->has($refund->cash_location_id)) {
                    $statistics['skipped']++;

                    return;
                }

                $this->createLedgerEntry([
                    'account_id' => $refund->account_id,
                    'finance_epoch_id' => $this->epochFor($epochs, $legacyEpoch, $refund->effectiveOccurredAt())->id,
                    'location_id' => $refund->cash_location_id,
                    'customer_purchase_refund_id' => $refund->id,
                    'source_key' => 'refund:'.$refund->id,
                    'direction' => StudioCashEntry::DirectionOut,
                    'purpose' => StudioCashEntry::PurposePaymentRefund,
                    'amount_cents' => $refund->amount_cents,
                    'currency' => $refund->currency,
                    'occurred_at' => $refund->effectiveOccurredAt(),
                    ...$this->actorAttributes($refund),
                    'reason' => $refund->reason,
                ], $statistics);
            });
    }

    /**
     * @param  Collection<int, FinanceEpoch>  $epochs
     */
    private function epochFor(Collection $epochs, FinanceEpoch $legacyEpoch, ?CarbonInterface $occurredAt): FinanceEpoch
    {
        if (! $occurredAt) {
            return $legacyEpoch;
        }

        return $epochs
            ->filter(fn (FinanceEpoch $epoch): bool => $epoch->starts_at->lessThanOrEqualTo($occurredAt))
            ->last() ?? $legacyEpoch;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{legacy_epoch_id: int, entries_created: int, entries_linked: int, expenses_normalized: int, skipped: int}  $statistics
     */
    private function createLedgerEntry(array $attributes, array &$statistics): void
    {
        $existingEntry = StudioCashEntry::query()
            ->where('source_key', $attributes['source_key'])
            ->first();

        if ($existingEntry) {
            foreach ([
                'account_id',
                'finance_epoch_id',
                'location_id',
                'studio_expense_id',
                'customer_purchase_id',
                'customer_purchase_correction_id',
                'customer_purchase_refund_id',
                'direction',
                'purpose',
                'amount_cents',
                'currency',
            ] as $attribute) {
                if ((string) $existingEntry->getAttribute($attribute) !== (string) ($attributes[$attribute] ?? null)) {
                    throw new LogicException(sprintf(
                        'Ledger source key [%s] already exists with a different %s.',
                        $attributes['source_key'],
                        $attribute,
                    ));
                }
            }

            return;
        }

        StudioCashEntry::query()->create($attributes);
        $statistics['entries_created']++;
    }

    /**
     * @return array{actor_user_id: int|null, actor_trainer_id: int|null, actor_name: string|null, actor_email: string|null, actor_role: string|null}
     */
    private function actorAttributes(Model $model): array
    {
        return [
            'actor_user_id' => $model->getAttribute('actor_user_id'),
            'actor_trainer_id' => $model->getAttribute('actor_trainer_id'),
            'actor_name' => $model->getAttribute('actor_name'),
            'actor_email' => $model->getAttribute('actor_email'),
            'actor_role' => $model->getAttribute('actor_role'),
        ];
    }

    /**
     * @return array{actor_user_id: int|null, actor_trainer_id: int|null, actor_name: string|null, actor_email: string|null, actor_role: string|null}
     */
    private function prefixedActorAttributes(Model $model, string $prefix): array
    {
        return [
            'actor_user_id' => $model->getAttribute($prefix.'_user_id'),
            'actor_trainer_id' => $model->getAttribute($prefix.'_trainer_id'),
            'actor_name' => $model->getAttribute($prefix.'_name'),
            'actor_email' => $model->getAttribute($prefix.'_email'),
            'actor_role' => $model->getAttribute($prefix.'_role'),
        ];
    }
}
