<?php

namespace Tests\Feature;

use App\Actions\CorrectCustomerPurchase;
use App\Actions\CreateStudioExpense;
use App\Actions\ReconcileCashbox;
use App\Actions\RecordStudioCashEntry;
use App\Actions\StartFinanceEpoch;
use App\Actions\VoidStudioExpense;
use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseCorrection;
use App\Models\CustomerPurchaseRefund;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use App\Support\Finance\CashboxBalanceService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class FinanceLedgerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cash_balance_uses_reconciliation_then_only_later_ledger_entries(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $cashAction = app(RecordStudioCashEntry::class);
        $balanceService = app(CashboxBalanceService::class);

        foreach ([
            ['direction' => StudioCashEntry::DirectionIn, 'amount' => 10000, 'source' => 'test:cash:100'],
            ['direction' => StudioCashEntry::DirectionIn, 'amount' => 5000, 'source' => 'test:cash:50'],
            ['direction' => StudioCashEntry::DirectionOut, 'amount' => 2000, 'source' => 'test:cash:minus20'],
            ['direction' => StudioCashEntry::DirectionIn, 'amount' => 2000, 'source' => 'test:cash:plus20'],
        ] as $movement) {
            $cashAction->execute(
                $account,
                $location,
                $movement['direction'],
                $movement['amount'],
                now(),
                $owner,
                'Cash scenario movement.',
                sourceKey: $movement['source'],
            );
        }

        $this->assertSame(15000, $balanceService->balanceFor($account, $location));

        app(ReconcileCashbox::class)->execute(
            $account,
            $location,
            8000,
            $owner,
            'Physical cash count.',
            (string) Str::uuid(),
        );

        $customer = Customer::factory()->for($account)->create();
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 1000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);
        $cashAction->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1000,
            now(),
            $owner,
            'Customer paid for a class.',
            StudioCashEntry::PurposeCustomerPayment,
            purchase: $purchase,
            sourceKey: 'purchase:'.$purchase->id.':cash-in',
        );

        $this->assertSame(9000, $balanceService->balanceFor($account, $location));

        $replayed = $cashAction->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1000,
            now(),
            $owner,
            'Customer paid for a class.',
            StudioCashEntry::PurposeCustomerPayment,
            purchase: $purchase,
            sourceKey: 'purchase:'.$purchase->id.':cash-in',
        );

        $this->assertSame(5, $account->studioCashEntries()->count());
        $this->assertSame($purchase->cashEntries()->sole()->id, $replayed->id);
        $this->assertSame(9000, $balanceService->balanceFor($account, $location));
    }

    public function test_cash_writes_lock_the_cashbox_location_for_concurrent_requests(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(RecordStudioCashEntry::class)->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1000,
            now(),
            $owner,
            'Concurrent cash write.',
            sourceKey: 'concurrency:test:cash',
        );

        $this->assertTrue(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'from `locations`')
                && str_contains($query, 'for update'),
        ));
    }

    public function test_cash_ledger_rejects_changed_replays_and_invalid_business_semantics(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $cashAction = app(RecordStudioCashEntry::class);
        $sourceKey = 'test:strict-ledger-replay';

        $cashAction->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1000,
            now(),
            $owner,
            'Original cash deposit.',
            sourceKey: $sourceKey,
        );

        try {
            $cashAction->execute(
                $account,
                $location,
                StudioCashEntry::DirectionIn,
                2000,
                now(),
                $owner,
                'Changed replay.',
                sourceKey: $sourceKey,
            );
            $this->fail('A changed payload reused an existing source key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_key', $exception->errors());
        }

        foreach ([
            [
                'direction' => StudioCashEntry::DirectionOut,
                'purpose' => StudioCashEntry::PurposeDeposit,
                'currency' => 'UAH',
                'expected_error' => 'purpose_direction',
            ],
            [
                'direction' => StudioCashEntry::DirectionOut,
                'purpose' => StudioCashEntry::PurposeOperationalExpense,
                'currency' => 'UAH',
                'expected_error' => 'purpose',
            ],
            [
                'direction' => StudioCashEntry::DirectionIn,
                'purpose' => StudioCashEntry::PurposeDeposit,
                'currency' => 'UA',
                'expected_error' => 'currency',
            ],
        ] as $index => $invalidEntry) {
            try {
                $cashAction->execute(
                    $account,
                    $location,
                    $invalidEntry['direction'],
                    1000,
                    now(),
                    $owner,
                    'Invalid ledger semantics.',
                    $invalidEntry['purpose'],
                    currency: $invalidEntry['currency'],
                    sourceKey: 'test:invalid-ledger:'.$index,
                );
                $this->fail('An invalid ledger entry was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($invalidEntry['expected_error'], $exception->errors());
            }
        }

        $this->assertSame(1, $account->studioCashEntries()->count());
        $this->assertSame(1000, app(CashboxBalanceService::class)->balanceFor($account, $location));
    }

    public function test_reconciliation_snapshots_expected_actual_variance_cutoff_and_rejects_changed_replay(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $cashAction = app(RecordStudioCashEntry::class);

        $entry = $cashAction->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            10000,
            now(),
            $owner,
            'Opening cash.',
            sourceKey: 'test:reconciliation:opening',
        );
        $idempotencyKey = (string) Str::uuid();
        $reconciliation = app(ReconcileCashbox::class)->execute(
            $account,
            $location,
            8000,
            $owner,
            'Physical count.',
            $idempotencyKey,
        );
        $replayed = app(ReconcileCashbox::class)->execute(
            $account,
            $location,
            8000,
            $owner,
            'Physical count.',
            $idempotencyKey,
        );

        $this->assertSame($reconciliation->id, $replayed->id);
        $this->assertSame(10000, $reconciliation->expected_before_cents);
        $this->assertSame(8000, $reconciliation->actual_counted_cents);
        $this->assertSame(-2000, $reconciliation->variance_cents);
        $this->assertSame($entry->id, $reconciliation->cutoff_cash_entry_id);

        try {
            app(ReconcileCashbox::class)->execute(
                $account,
                $location,
                8100,
                $owner,
                'Changed replay.',
                $idempotencyKey,
            );
            $this->fail('A reconciliation idempotency key was reused with another amount.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $cashAction->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1000,
            now(),
            $owner,
            'Payment after count.',
            sourceKey: 'test:reconciliation:after',
        );
        $second = app(ReconcileCashbox::class)->execute(
            $account,
            $location,
            9000,
            $owner,
            'Second physical count.',
            (string) Str::uuid(),
        );

        $this->assertSame(9000, $second->expected_before_cents);
        $this->assertSame(0, $second->variance_cents);
        $this->assertSame(9000, app(CashboxBalanceService::class)->balanceFor($account, $location));
    }

    public function test_new_epoch_supports_multiple_cashboxes_and_currencies_without_old_movements(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create();
        $secondLocation = Location::factory()->for($account)->create();
        $cashAction = app(RecordStudioCashEntry::class);

        $cashAction->execute($account, $firstLocation, StudioCashEntry::DirectionIn, 50000, now(), $owner, 'Legacy UAH.', sourceKey: 'legacy:test:uah');
        $cashAction->execute($account, $firstLocation, StudioCashEntry::DirectionIn, 900, now(), $owner, 'Legacy USD.', currency: 'USD', sourceKey: 'legacy:test:usd');

        $idempotencyKey = (string) Str::uuid();
        $cashboxes = [
            ['location_id' => $firstLocation->id, 'currency' => 'UAH', 'actual_counted_cents' => 7000],
            ['location_id' => $firstLocation->id, 'currency' => 'USD', 'actual_counted_cents' => 300],
            ['location_id' => $secondLocation->id, 'currency' => 'UAH', 'actual_counted_cents' => 100],
            ['location_id' => $secondLocation->id, 'currency' => 'USD', 'actual_counted_cents' => 0],
        ];

        try {
            app(StartFinanceEpoch::class)->execute(
                $account,
                array_slice($cashboxes, 0, 3),
                $owner,
                'Incomplete inventory.',
                (string) Str::uuid(),
            );
            $this->fail('A currency cashbox was allowed to be omitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cashboxes', $exception->errors());
        }

        $epoch = app(StartFinanceEpoch::class)->execute($account, $cashboxes, $owner, 'Initial inventory of every cashbox.', $idempotencyKey);
        $sameEpoch = app(StartFinanceEpoch::class)->execute($account, $cashboxes, $owner, 'Initial inventory of every cashbox.', $idempotencyKey);

        $this->assertSame($epoch->id, $sameEpoch->id);
        $this->assertCount(4, $epoch->reconciliations);
        $this->assertSame(7000, app(CashboxBalanceService::class)->balanceFor($account, $firstLocation, 'UAH'));
        $this->assertSame(300, app(CashboxBalanceService::class)->balanceFor($account, $firstLocation, 'USD'));
        $this->assertSame(100, app(CashboxBalanceService::class)->balanceFor($account, $secondLocation, 'UAH'));
        $this->assertSame(0, app(CashboxBalanceService::class)->balanceFor($account, $secondLocation, 'USD'));
        $this->assertSame(1, $account->financeEpochs()->where('is_legacy', false)->count());
    }

    public function test_cash_movement_cannot_be_backdated_across_the_active_epoch_boundary(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $epochStartedAt = now()->startOfSecond();

        app(StartFinanceEpoch::class)->execute(
            $account,
            [[
                'location_id' => $location->id,
                'currency' => 'UAH',
                'actual_counted_cents' => 5000,
            ]],
            $owner,
            'Start trusted cash accounting.',
            (string) Str::uuid(),
            $epochStartedAt,
        );

        try {
            app(RecordStudioCashEntry::class)->execute(
                $account,
                $location,
                StudioCashEntry::DirectionIn,
                1000,
                $epochStartedAt->copy()->subSecond(),
                $owner,
                'Attempted late historical posting.',
                sourceKey: 'test:backdated-across-epoch',
            );
            $this->fail('A cash movement was backdated across the trusted epoch boundary.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('occurred_at', $exception->errors());
        }

        $this->assertSame(0, $account->studioCashEntries()->count());
        $this->assertSame(5000, app(CashboxBalanceService::class)->balanceFor($account, $location));
    }

    public function test_starting_a_new_epoch_requires_typed_approval_in_the_confirmation_modal(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $payload = [
            'cashboxes' => [[
                'location_id' => $location->id,
                'currency' => 'UAH',
                'actual_amount' => '80.00',
            ]],
            'reason' => 'Verified physical cash count.',
        ];

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.cash.index', $account))
            ->assertOk()
            ->assertSeeInOrder([__('app.cash_now'), __('app.cash_control_operations')])
            ->assertSee('data-confirm-phrase="approve"', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.finance-epochs.store', $account), $payload)
            ->assertSessionHasErrors('approval');

        $this->assertSame(0, $account->financeEpochs()->where('is_legacy', false)->count());

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.finance-epochs.store', $account), [...$payload, 'approval' => 'restart'])
            ->assertSessionHasErrors('approval');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.finance-epochs.store', $account), [...$payload, 'approval' => 'approve'])
            ->assertRedirect(route('dashboard.accounts.cash.index', $account));

        $this->assertSame(1, $account->financeEpochs()->where('is_legacy', false)->count());
        $this->assertSame(8000, app(CashboxBalanceService::class)->balanceFor($account, $location));
    }

    public function test_only_cash_expense_changes_selected_cashbox_and_void_is_a_reversal(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $operationalLocation = Location::factory()->for($account)->create();
        $cashLocation = Location::factory()->for($account)->create();
        $category = ExpenseCategory::factory()->for($account)->create();
        $createExpense = app(CreateStudioExpense::class);

        $createExpense->execute($account, $category, $operationalLocation, StudioExpense::PaymentMethodBankCard, 2000, now(), $owner, 'Paid using a bank card.');
        $cashExpense = $createExpense->execute($account, $category, $operationalLocation, StudioExpense::PaymentMethodCashdesk, 3000, now(), $owner, 'Paid from another location cashbox.', $cashLocation);

        $this->assertSame(1, $account->studioCashEntries()->count());
        $this->assertSame($operationalLocation->id, $cashExpense->expense_location_id);
        $this->assertSame($cashLocation->id, $cashExpense->cash_location_id);
        $this->assertSame(-3000, app(CashboxBalanceService::class)->balanceFor($account, $cashLocation));

        app(VoidStudioExpense::class)->execute($account, $cashExpense, $owner, 'Expense was entered twice.');

        $this->assertSame(2, $account->studioCashEntries()->count());
        $this->assertSame(0, app(CashboxBalanceService::class)->balanceFor($account, $cashLocation));
    }

    public function test_cash_ledger_and_reconciliations_are_immutable(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $entry = app(RecordStudioCashEntry::class)->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1000,
            now(),
            $owner,
            'Opening cash.',
            sourceKey: 'immutable:test:cash',
        );
        $reconciliation = app(ReconcileCashbox::class)->execute(
            $account,
            $location,
            1000,
            $owner,
            'Cash count.',
            (string) Str::uuid(),
        );
        $epoch = $entry->financeEpoch;

        foreach ([
            fn () => $entry->update(['amount_cents' => 2000]),
            fn () => $entry->delete(),
            fn () => $reconciliation->update(['actual_counted_cents' => 2000]),
            fn () => $reconciliation->delete(),
            fn () => $epoch->update(['reason' => 'Changed.']),
            fn () => $epoch->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('An append-only finance record was mutated.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_expense_and_payment_correction_roll_back_when_a_later_ledger_write_fails(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create();
        $secondLocation = Location::factory()->for($account)->create();
        $category = ExpenseCategory::factory()->for($account)->create();
        $cashAction = app(RecordStudioCashEntry::class);

        $nextExpenseId = $this->nextAutoIncrementId('studio_expenses');
        $cashAction->execute(
            $account,
            $firstLocation,
            StudioCashEntry::DirectionIn,
            1,
            now(),
            $owner,
            'Reserve a conflicting source key.',
            sourceKey: 'expense:'.$nextExpenseId.':out',
        );

        try {
            app(CreateStudioExpense::class)->execute(
                $account,
                $category,
                $firstLocation,
                StudioExpense::PaymentMethodCashdesk,
                2500,
                now(),
                $owner,
                'Must roll back after the ledger conflict.',
                $firstLocation,
            );
            $this->fail('An expense survived a failed ledger write.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_key', $exception->errors());
        }

        $this->assertSame(0, $account->studioExpenses()->count());

        $customer = Customer::factory()->for($account)->create();
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($firstLocation)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 5000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);
        $nextCorrectionId = $this->nextAutoIncrementId('customer_purchase_corrections');
        $cashAction->execute(
            $account,
            $secondLocation,
            StudioCashEntry::DirectionIn,
            1,
            now(),
            $owner,
            'Reserve the corrected-entry source key.',
            sourceKey: 'correction:'.$nextCorrectionId.':corrected',
        );

        try {
            app(CorrectCustomerPurchase::class)->execute(
                $account,
                $purchase,
                $secondLocation,
                7000,
                now(),
                $owner,
                'Must roll back both correction entries.',
            );
            $this->fail('A correction survived a failed second ledger write.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_key', $exception->errors());
        }

        $this->assertSame(5000, $purchase->fresh()->amount_cents);
        $this->assertSame($firstLocation->id, $purchase->fresh()->location_id);
        $this->assertSame(0, $purchase->corrections()->count());
        $this->assertSame(0, $account->studioCashEntries()->where('customer_purchase_id', $purchase->id)->count());
    }

    public function test_backfill_is_idempotent_for_purchase_correction_refund_and_expense(): void
    {
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $location = Location::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $purchase = CustomerPurchase::factory()->for($account)->for($customer)->for($location)->create([
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => 12000,
            'currency' => 'UAH',
            'paid_at' => now()->subDays(3),
        ]);
        $correction = CustomerPurchaseCorrection::factory()->for($account)->for($purchase, 'customerPurchase')->create([
            'previous_location_id' => $location->id,
            'new_location_id' => $location->id,
            'previous_amount_cents' => 10000,
            'new_amount_cents' => 12000,
            'previous_paid_at' => now()->subDays(4),
            'new_paid_at' => now()->subDays(3),
        ]);
        $refund = CustomerPurchaseRefund::factory()->for($account)->for($purchase, 'customerPurchase')->create([
            'location_id' => $location->id,
            'cash_location_id' => $location->id,
            'method' => CustomerPurchaseRefund::MethodCash,
            'amount_cents' => 2000,
            'currency' => 'UAH',
            'refunded_at' => now()->subDay(),
        ]);
        $category = ExpenseCategory::factory()->for($account)->create();
        $expense = StudioExpense::factory()->for($account)->for($category, 'category')->create([
            'location_id' => $location->id,
            'payment_method' => StudioExpense::PaymentMethodCashdesk,
            'amount_cents' => 1500,
            'currency' => 'UAH',
        ]);

        $this->assertSame(0, $account->studioCashEntries()->count());
        $this->assertSame(0, Artisan::call('finance:backfill-ledger', ['--account' => $account->id]));
        $this->assertSame(0, Artisan::call('finance:backfill-ledger', ['--account' => $account->id]));

        $this->assertSame(5, $account->studioCashEntries()->count());
        foreach ([
            'purchase:'.$purchase->id.':cash-in',
            'correction:'.$correction->id.':reversal',
            'correction:'.$correction->id.':corrected',
            'refund:'.$refund->id,
            'expense:'.$expense->id.':out',
        ] as $sourceKey) {
            $this->assertDatabaseHas('studio_cash_entries', ['source_key' => $sourceKey]);
        }
    }

    public function test_backfill_excludes_non_cash_records_preserves_void_reversals_and_assigns_epochs_by_time(): void
    {
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $otherAccount = Account::factory()->create(['default_currency' => 'UAH']);
        $location = Location::factory()->for($account)->create();
        Location::factory()->for($otherAccount)->create();
        $customer = Customer::factory()->for($account)->create();
        $category = ExpenseCategory::factory()->for($account)->create();
        $newEpoch = $account->financeEpochs()->create([
            'starts_at' => now()->subDays(2),
            'is_legacy' => false,
            'reason' => 'Existing reconciled era.',
        ]);
        $cashPurchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashClassPass,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 10000,
                'currency' => 'UAH',
                'paid_at' => now()->subDays(3),
            ]);
        $failedCashPurchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashClassPass,
                'status' => CustomerPurchaseStatus::PaymentFailed->value,
                'amount_cents' => 8000,
                'currency' => 'UAH',
                'paid_at' => now()->subDay(),
            ]);
        $onlinePurchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->create([
                'provider' => 'liqpay',
                'payment_source' => CustomerPurchase::SourceOnlineCheckout,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 7000,
                'currency' => 'UAH',
                'paid_at' => now()->subDay(),
            ]);
        $voidedCashExpense = StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->create([
                'location_id' => $location->id,
                'expense_location_id' => $location->id,
                'cash_location_id' => $location->id,
                'payment_method' => StudioExpense::PaymentMethodCashdesk,
                'amount_cents' => 2500,
                'occurred_at' => now()->subDay(),
                'voided_at' => now()->subHours(12),
                'void_reason' => 'Legacy duplicate expense.',
            ]);
        $cardExpense = StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->create([
                'location_id' => $location->id,
                'expense_location_id' => $location->id,
                'payment_method' => StudioExpense::PaymentMethodBankCard,
                'amount_cents' => 3000,
                'occurred_at' => now()->subDay(),
            ]);
        $cashRefund = CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($cashPurchase, 'customerPurchase')
            ->create([
                'location_id' => $location->id,
                'cash_location_id' => $location->id,
                'method' => CustomerPurchaseRefund::MethodCash,
                'amount_cents' => 1000,
                'currency' => 'UAH',
                'refunded_at' => now()->subHours(6),
            ]);
        $cashlessRefund = CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($cashPurchase, 'customerPurchase')
            ->create([
                'location_id' => $location->id,
                'cash_location_id' => null,
                'method' => CustomerPurchaseRefund::MethodCashless,
                'amount_cents' => 500,
                'currency' => 'UAH',
                'refunded_at' => now()->subHours(5),
            ]);

        $this->assertSame(0, Artisan::call('finance:backfill-ledger', ['--account' => $account->id]));
        $this->assertSame(0, Artisan::call('finance:backfill-ledger', ['--account' => $account->id]));

        $legacyEpoch = $account->financeEpochs()->where('is_legacy', true)->sole();

        $this->assertSame($legacyEpoch->id, $cashPurchase->cashEntries()->sole()->finance_epoch_id);
        $this->assertSame($newEpoch->id, $voidedCashExpense->cashEntries()->first()->finance_epoch_id);
        $this->assertSame(2, $voidedCashExpense->cashEntries()->count());
        $this->assertSame($newEpoch->id, $cashRefund->cashEntry()->sole()->finance_epoch_id);
        $this->assertSame(0, $failedCashPurchase->cashEntries()->count());
        $this->assertSame(0, $onlinePurchase->cashEntries()->count());
        $this->assertSame(0, $cardExpense->cashEntries()->count());
        $this->assertSame(0, $cashlessRefund->cashEntry()->count());
        $this->assertSame(4, $account->studioCashEntries()->count());
        $this->assertSame(0, $otherAccount->studioCashEntries()->count());

        $this->assertSame(1, Artisan::call('finance:backfill-ledger', ['--account' => 'invalid']));
        $this->assertSame(4, $account->studioCashEntries()->count());
    }

    private function nextAutoIncrementId(string $table): int
    {
        $result = DB::selectOne(
            'SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return (int) $result->next_id;
    }
}
