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
        ?Location $location,
        string $paymentMethod,
        int $amountCents,
        CarbonInterface $occurredAt,
        ?User $user,
        string $reason,
    ): StudioExpense {
        validator(
            [
                'payment_method' => $paymentMethod,
                'amount_cents' => $amountCents,
            ],
            [
                'payment_method' => ['required', Rule::in(StudioExpense::paymentMethods())],
                'amount_cents' => ['required', 'integer', 'min:1'],
            ],
        )->validate();

        return DB::transaction(function () use ($account, $expenseCategory, $location, $paymentMethod, $amountCents, $occurredAt, $user, $reason): StudioExpense {
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

            $lockedLocation = $location
                ? Location::query()
                    ->whereBelongsTo($account)
                    ->whereKey($location->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;

            if ($paymentMethod === StudioExpense::PaymentMethodCashdesk && ! $lockedLocation) {
                throw ValidationException::withMessages([
                    'location_id' => __('validation.required', ['attribute' => __('app.location')]),
                ]);
            }

            $expense = StudioExpense::query()->create([
                'account_id' => $account->id,
                'expense_category_id' => $lockedCategory->id,
                'location_id' => $lockedLocation?->id,
                'amount_cents' => $amountCents,
                'currency' => $account->default_currency,
                'payment_method' => $paymentMethod,
                'occurred_at' => $occurredAt,
                ...$this->actorSnapshot->capture($account, $user),
                'reason' => $reason,
            ]);

            if ($paymentMethod === StudioExpense::PaymentMethodCashdesk) {
                $this->recordStudioCashEntry->execute(
                    $account,
                    $lockedLocation,
                    StudioCashEntry::DirectionOut,
                    $amountCents,
                    $occurredAt,
                    $user,
                    $reason,
                    StudioCashEntry::PurposeOperationalExpense,
                    $expense,
                );
            }

            return $expense;
        }, 5);
    }
}
