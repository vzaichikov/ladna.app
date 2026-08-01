<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CashboxReconciliation;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\Finance\CashboxBalanceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartFinanceEpoch
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly CashboxBalanceService $cashboxBalanceService,
    ) {}

    /**
     * @param  array<int, array{location_id: int, actual_counted_cents: int, currency?: string}>  $cashboxes
     */
    public function execute(
        Account $account,
        array $cashboxes,
        ?User $user,
        string $reason,
        string $idempotencyKey,
        ?CarbonInterface $occurredAt = null,
    ): FinanceEpoch {
        $occurredAt ??= now();
        $cashboxes = $this->validatedCashboxes($account, $cashboxes, $reason, $idempotencyKey);

        return DB::transaction(function () use ($account, $cashboxes, $user, $reason, $idempotencyKey, $occurredAt): FinanceEpoch {
            $existingEpoch = FinanceEpoch::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingEpoch) {
                $this->ensureIdempotentMatch($existingEpoch, $account, $cashboxes, $reason);

                return $existingEpoch->load('reconciliations');
            }

            $lockedLocations = Location::query()
                ->whereBelongsTo($account)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $this->ensureEveryCashboxIsCounted($account, $cashboxes);
            $existingEpoch = FinanceEpoch::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingEpoch) {
                $this->ensureIdempotentMatch($existingEpoch, $account, $cashboxes, $reason);

                return $existingEpoch->load('reconciliations');
            }

            $previousEpoch = $account->activeFinanceEpoch();
            if ($previousEpoch && $previousEpoch->starts_at->greaterThan($occurredAt)) {
                throw ValidationException::withMessages([
                    'occurred_at' => __('validation.after_or_equal', [
                        'attribute' => 'occurred at',
                        'date' => $previousEpoch->starts_at->toDateTimeString(),
                    ]),
                ]);
            }

            $snapshots = $cashboxes->map(function (array $cashbox) use ($account, $lockedLocations, $previousEpoch): array {
                /** @var Location $location */
                $location = $lockedLocations->get($cashbox['location_id']);
                $expectedBeforeCents = $previousEpoch
                    ? $this->cashboxBalanceService->balanceFor($account, $location, $cashbox['currency'], $previousEpoch)
                    : 0;
                $cutoffCashEntryId = (int) StudioCashEntry::query()
                    ->whereBelongsTo($account)
                    ->whereBelongsTo($location)
                    ->where('currency', $cashbox['currency'])
                    ->max('id');

                return [
                    ...$cashbox,
                    'expected_before_cents' => $expectedBeforeCents,
                    'cutoff_cash_entry_id' => $cutoffCashEntryId > 0 ? $cutoffCashEntryId : null,
                ];
            });
            $epoch = $account->financeEpochs()->create([
                'created_by_user_id' => $user?->id,
                'starts_at' => $occurredAt,
                'is_legacy' => false,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
            ]);
            $actor = $this->actorSnapshot->capture($account, $user);

            foreach ($snapshots as $snapshot) {
                $epoch->reconciliations()->create([
                    'account_id' => $account->id,
                    'location_id' => $snapshot['location_id'],
                    'cutoff_cash_entry_id' => $snapshot['cutoff_cash_entry_id'],
                    'kind' => CashboxReconciliation::KindEpochStart,
                    'currency' => $snapshot['currency'],
                    'expected_before_cents' => $snapshot['expected_before_cents'],
                    'actual_counted_cents' => $snapshot['actual_counted_cents'],
                    'variance_cents' => $snapshot['actual_counted_cents'] - $snapshot['expected_before_cents'],
                    'idempotency_key' => (string) Str::uuid(),
                    'occurred_at' => $occurredAt,
                    ...$actor,
                    'reason' => $reason,
                ]);
            }

            return $epoch->load('reconciliations');
        }, attempts: 5);
    }

    /**
     * @param  array<int, array{location_id: int, actual_counted_cents: int, currency?: string}>  $cashboxes
     * @return Collection<int, array{location_id: int, actual_counted_cents: int, currency: string}>
     */
    private function validatedCashboxes(Account $account, array $cashboxes, string $reason, string $idempotencyKey): Collection
    {
        $normalizedCashboxes = collect($cashboxes)
            ->map(fn (array $cashbox): array => [
                'location_id' => $cashbox['location_id'] ?? null,
                'actual_counted_cents' => $cashbox['actual_counted_cents'] ?? null,
                'currency' => Str::upper((string) ($cashbox['currency'] ?? $account->default_currency)),
            ]);

        validator(
            [
                'cashboxes' => $normalizedCashboxes->all(),
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'cashboxes' => ['required', 'array', 'min:1'],
                'cashboxes.*.location_id' => ['required', 'integer'],
                'cashboxes.*.actual_counted_cents' => ['required', 'integer', 'min:0'],
                'cashboxes.*.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                'reason' => ['required', 'string', 'min:3', 'max:2000'],
                'idempotency_key' => ['required', 'uuid'],
            ],
        )->validate();

        $normalizedCashboxes = $normalizedCashboxes->map(fn (array $cashbox): array => [
            'location_id' => (int) $cashbox['location_id'],
            'actual_counted_cents' => (int) $cashbox['actual_counted_cents'],
            'currency' => $cashbox['currency'],
        ]);

        if ($normalizedCashboxes->duplicates(fn (array $cashbox): string => $cashbox['location_id'].':'.$cashbox['currency'])->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cashboxes' => __('validation.distinct', ['attribute' => 'cashboxes']),
            ]);
        }

        $accountLocationIds = $account->locations()->orderBy('id')->pluck('id');
        $submittedLocationIds = $normalizedCashboxes->pluck('location_id')->unique()->sort()->values();

        if ($accountLocationIds->diff($submittedLocationIds)->isNotEmpty()
            || $submittedLocationIds->diff($accountLocationIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cashboxes' => __('validation.exists', ['attribute' => 'cashbox location']),
            ]);
        }

        return $normalizedCashboxes;
    }

    /**
     * @param  Collection<int, array{location_id: int, actual_counted_cents: int, currency: string}>  $cashboxes
     */
    private function ensureEveryCashboxIsCounted(Account $account, Collection $cashboxes): void
    {
        $requiredCashboxKeys = $this->cashboxBalanceService
            ->forAccount($account)
            ->map(fn (array $cashbox): string => $cashbox['location_id'].':'.$cashbox['currency'])
            ->sort()
            ->values();
        $submittedCashboxKeys = $cashboxes
            ->map(fn (array $cashbox): string => $cashbox['location_id'].':'.$cashbox['currency'])
            ->sort()
            ->values();

        if ($requiredCashboxKeys->diff($submittedCashboxKeys)->isNotEmpty()
            || $submittedCashboxKeys->diff($requiredCashboxKeys)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cashboxes' => __('app.finance_epoch_all_cashboxes_required'),
            ]);
        }
    }

    /**
     * @param  Collection<int, array{location_id: int, actual_counted_cents: int, currency: string}>  $cashboxes
     */
    private function ensureIdempotentMatch(
        FinanceEpoch $epoch,
        Account $account,
        Collection $cashboxes,
        string $reason,
    ): void {
        $submittedCashboxes = $cashboxes
            ->sortBy(fn (array $cashbox): string => $cashbox['location_id'].':'.$cashbox['currency'])
            ->values()
            ->all();
        $recordedCashboxes = $epoch->reconciliations()
            ->where('kind', CashboxReconciliation::KindEpochStart)
            ->get()
            ->map(fn (CashboxReconciliation $reconciliation): array => [
                'location_id' => $reconciliation->location_id,
                'actual_counted_cents' => $reconciliation->actual_counted_cents,
                'currency' => $reconciliation->currency,
            ])
            ->sortBy(fn (array $cashbox): string => $cashbox['location_id'].':'.$cashbox['currency'])
            ->values()
            ->all();

        if ($epoch->account_id !== $account->id
            || $epoch->reason !== $reason
            || $recordedCashboxes !== $submittedCashboxes) {
            $this->throwDuplicateIdempotencyKey();
        }
    }

    private function throwDuplicateIdempotencyKey(): never
    {
        throw ValidationException::withMessages([
            'idempotency_key' => __('validation.unique', ['attribute' => 'idempotency key']),
        ]);
    }
}
