<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ExpenseCategory;
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

class CreateStudioExpense
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly RecordStudioCashEntry $recordStudioCashEntry,
    ) {}

    public function execute(
        Account $account,
        ExpenseCategory $expenseCategory,
        ?Location $expenseLocation,
        string $paymentMethod,
        int $amountCents,
        CarbonInterface $occurredAt,
        ?User $user,
        string $reason,
        ?Location $cashLocation = null,
        ?string $idempotencyKey = null,
    ): StudioExpense {
        $idempotencyKey ??= (string) Str::uuid();

        validator(
            [
                'payment_method' => $paymentMethod,
                'amount_cents' => $amountCents,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'payment_method' => ['required', Rule::in(StudioExpense::paymentMethods())],
                'amount_cents' => ['required', 'integer', 'min:1'],
                'idempotency_key' => ['required', 'uuid'],
            ],
        )->validate();

        return DB::transaction(function () use ($account, $expenseCategory, $expenseLocation, $paymentMethod, $amountCents, $occurredAt, $user, $reason, $cashLocation, $idempotencyKey): StudioExpense {
            Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $lockedCategory = ExpenseCategory::query()
                ->whereBelongsTo($account)
                ->whereKey($expenseCategory->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedCategory->is_active) {
                throw ValidationException::withMessages([
                    'expense_category_id' => __('app.expense_category_inactive'),
                ]);
            }

            $lockedExpenseLocation = $expenseLocation
                ? Location::query()
                    ->whereBelongsTo($account)
                    ->whereKey($expenseLocation->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;

            $lockedCashLocation = $cashLocation
                ? Location::query()
                    ->whereBelongsTo($account)
                    ->whereKey($cashLocation->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;

            if ($paymentMethod === StudioExpense::PaymentMethodCashdesk && ! $lockedCashLocation) {
                throw ValidationException::withMessages([
                    'cash_location_id' => __('validation.required', ['attribute' => __('app.location')]),
                ]);
            }

            if ($paymentMethod !== StudioExpense::PaymentMethodCashdesk && $lockedCashLocation) {
                throw ValidationException::withMessages([
                    'cash_location_id' => __('app.expense_cash_location_cash_only'),
                ]);
            }

            $existingExpense = StudioExpense::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingExpense) {
                $this->ensureIdempotentMatch(
                    $existingExpense,
                    $account,
                    $lockedCategory,
                    $lockedExpenseLocation,
                    $lockedCashLocation,
                    $paymentMethod,
                    $amountCents,
                    $occurredAt,
                    $reason,
                );

                return $existingExpense;
            }

            $expense = StudioExpense::query()->create([
                'account_id' => $account->id,
                'expense_category_id' => $lockedCategory->id,
                'location_id' => $lockedExpenseLocation?->id,
                'expense_location_id' => $lockedExpenseLocation?->id,
                'cash_location_id' => $lockedCashLocation?->id,
                'amount_cents' => $amountCents,
                'currency' => $account->default_currency,
                'payment_method' => $paymentMethod,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $occurredAt,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);

            if ($paymentMethod === StudioExpense::PaymentMethodCashdesk) {
                $this->recordStudioCashEntry->execute(
                    $account,
                    $lockedCashLocation,
                    StudioCashEntry::DirectionOut,
                    $amountCents,
                    $occurredAt,
                    $user,
                    $reason,
                    StudioCashEntry::PurposeOperationalExpense,
                    $expense,
                    sourceKey: 'expense:'.$expense->id.':out',
                );
            }

            return $expense;
        }, 5);
    }

    private function ensureIdempotentMatch(
        StudioExpense $expense,
        Account $account,
        ExpenseCategory $category,
        ?Location $expenseLocation,
        ?Location $cashLocation,
        string $paymentMethod,
        int $amountCents,
        CarbonInterface $occurredAt,
        string $reason,
    ): void {
        if ($expense->account_id !== $account->id
            || $expense->expense_category_id !== $category->id
            || $expense->expense_location_id !== $expenseLocation?->id
            || $expense->cash_location_id !== $cashLocation?->id
            || $expense->payment_method !== $paymentMethod
            || $expense->amount_cents !== $amountCents
            || ! $expense->occurred_at->equalTo($occurredAt)
            || $expense->reason !== $reason) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('validation.unique', ['attribute' => 'idempotency key']),
            ]);
        }
    }
}
