<?php

namespace Tests\Feature;

use App\Actions\CreateStudioExpense;
use App\Actions\StartFinanceEpoch;
use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\PayrollRun;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use App\Support\Finance\CashboxBalanceService;
use App\Support\Finance\PayrollPeriodResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FinanceConcurrencyTest extends TestCase
{
    /** @var array<int, int> */
    private array $accountIds = [];

    /** @var array<int, int> */
    private array $userIds = [];

    private string $cleanupToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupToken = 'finance-concurrency-'.Str::uuid();
    }

    public function test_competing_cash_writes_are_serialized_without_duplicates_or_lost_updates(): void
    {
        [, $account, $location] = $this->accountContext();
        $sameSourceKey = 'concurrency:cash:'.Str::uuid();
        $sameCode = $this->cashEntryCode($account, $location, 1000, $sameSourceKey);
        $sameProcesses = $this->runConcurrently([$sameCode, $sameCode]);

        $this->assertProcessesSucceeded($sameProcesses);
        $this->assertSame(1, $account->studioCashEntries()->count());
        $this->assertSame(1000, app(CashboxBalanceService::class)->balanceFor($account, $location));

        $differentProcesses = $this->runConcurrently([
            $this->cashEntryCode($account, $location, 1000, 'concurrency:cash:'.Str::uuid()),
            $this->cashEntryCode($account, $location, 1000, 'concurrency:cash:'.Str::uuid()),
        ]);

        $this->assertProcessesSucceeded($differentProcesses);
        $this->assertSame(3, $account->studioCashEntries()->count());
        $this->assertSame(3000, app(CashboxBalanceService::class)->balanceFor($account, $location));
    }

    public function test_competing_partial_refunds_cannot_exceed_the_remaining_amount(): void
    {
        [, $account, $location] = $this->accountContext();
        $customer = Customer::factory()->for($account)->create();
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'class_pass_plan_id' => null,
                'amount_cents' => 10000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);
        $processes = $this->runConcurrently([
            $this->refundCode($account, $purchase, 6000, (string) Str::uuid()),
            $this->refundCode($account, $purchase, 6000, (string) Str::uuid()),
        ]);

        $this->assertSame(1, collect($processes)->filter->isSuccessful()->count());
        $this->assertSame(1, CustomerPurchaseRefund::query()->whereBelongsTo($account)->count());
        $this->assertSame(6000, (int) $purchase->refunds()->sum('amount_cents'));
        $this->assertSame(4000, $purchase->fresh()->remainingRefundableAmountCents());
    }

    public function test_competing_expense_voids_create_one_reversal(): void
    {
        [$owner, $account, $location] = $this->accountContext();
        $category = ExpenseCategory::factory()->for($account)->create();
        $expense = app(CreateStudioExpense::class)->execute(
            $account,
            $category,
            $location,
            StudioExpense::PaymentMethodCashdesk,
            2500,
            now(),
            $owner,
            'Concurrent void fixture.',
            $location,
        );
        $code = $this->voidExpenseCode($account, $expense, $owner);
        $processes = $this->runConcurrently([$code, $code]);

        $this->assertProcessesSucceeded($processes);
        $this->assertTrue($expense->fresh()->isVoided());
        $this->assertSame(2, $expense->cashEntries()->count());
        $this->assertSame(1, $expense->cashEntries()->where('purpose', StudioCashEntry::PurposeExpenseReversal)->count());
        $this->assertSame(0, app(CashboxBalanceService::class)->balanceFor($account, $location));
    }

    public function test_reconciliation_racing_with_payment_has_a_valid_serial_order(): void
    {
        [$owner, $account, $location] = $this->accountContext();
        app(StartFinanceEpoch::class)->execute(
            $account,
            [[
                'location_id' => $location->id,
                'currency' => 'UAH',
                'actual_counted_cents' => 5000,
            ]],
            $owner,
            'Concurrency baseline.',
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
                'class_pass_plan_id' => null,
                'amount_cents' => 1000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);
        $processes = $this->runConcurrently([
            $this->customerPaymentLedgerCode($account, $location, $purchase),
            $this->reconciliationCode($account, $location, $owner),
        ]);

        $this->assertProcessesSucceeded($processes);
        $balance = app(CashboxBalanceService::class)->balanceFor($account, $location);

        $this->assertContains($balance, [5000, 6000]);
        $this->assertSame(1, $account->studioCashEntries()->count());
        $this->assertSame(2, $account->cashboxReconciliations()->count());
        $this->assertSame(5000, $account->cashboxReconciliations()->latest('id')->firstOrFail()->actual_counted_cents);
    }

    public function test_competing_epoch_and_payroll_close_replays_create_one_snapshot_each(): void
    {
        [$owner, $account, $location] = $this->accountContext();
        $epochKey = (string) Str::uuid();
        $epochCode = $this->epochCode($account, $location, $owner, $epochKey);
        $epochProcesses = $this->runConcurrently([$epochCode, $epochCode]);

        $this->assertProcessesSucceeded($epochProcesses);
        $this->assertSame(1, $account->financeEpochs()->where('is_legacy', false)->count());
        $this->assertSame(1, $account->cashboxReconciliations()->count());

        $period = app(PayrollPeriodResolver::class)->latestCompleted($account);
        $payrollKey = (string) Str::uuid();
        $payrollCode = $this->payrollCloseCode(
            $account,
            $owner,
            $period['starts_on']->toDateString(),
            $period['ends_on']->toDateString(),
            $payrollKey,
        );
        $payrollProcesses = $this->runConcurrently([$payrollCode, $payrollCode]);

        $this->assertProcessesSucceeded($payrollProcesses);
        $this->assertSame(1, PayrollRun::query()->whereBelongsTo($account)->count());
        $this->assertSame(0, $account->studioCashEntries()->count());
    }

    protected function tearDown(): void
    {
        try {
            $this->assertSame(0, DB::transactionLevel());

            $accountIds = Account::query()
                ->whereIn('id', $this->accountIds)
                ->orWhere('name', 'like', 'Finance concurrency %')
                ->pluck('id')
                ->all();

            foreach (array_reverse($accountIds) as $accountId) {
                Account::query()->find($accountId)?->delete();
            }

            DB::table('accounts')->whereIn('id', $accountIds)->delete();
            User::query()
                ->whereIn('id', $this->userIds)
                ->orWhere('email', 'like', 'finance-concurrency-%@example.test')
                ->delete();

            $this->assertFalse(Account::query()->whereIn('id', $accountIds)->exists());
            $this->assertFalse(User::query()->whereIn('id', $this->userIds)->exists());
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return array{0: User, 1: Account, 2: Location}
     */
    private function accountContext(): array
    {
        $owner = User::factory()->create([
            'name' => 'Finance concurrency owner',
            'email' => $this->cleanupToken.'@example.test',
        ]);
        $account = Account::factory()->create([
            'name' => 'Finance concurrency '.$this->cleanupToken,
            'default_currency' => 'UAH',
            'timezone' => 'UTC',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $this->accountIds[] = $account->id;
        $this->userIds[] = $owner->id;

        return [$owner, $account, $location];
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, Process>
     */
    private function runConcurrently(array $codes): array
    {
        $processes = collect($codes)
            ->map(fn (string $code): Process => new Process(
                [PHP_BINARY, base_path('artisan'), 'tinker', '--execute', $code],
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    'APP_CONFIG_CACHE' => '/tmp/ladna-tests-config.php',
                    'DB_DATABASE' => 'ld_ladna_testing',
                    'LADNA_TEST_DATABASE' => 'ld_ladna_testing',
                ],
                timeout: 20,
            ))
            ->all();

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();
        }

        return $processes;
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private function assertProcessesSucceeded(array $processes): void
    {
        foreach ($processes as $process) {
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput().' '.$process->getOutput()),
            );
        }
    }

    private function cashEntryCode(Account $account, Location $location, int $amountCents, string $sourceKey): string
    {
        return sprintf(
            'app(\\App\\Actions\\RecordStudioCashEntry::class)->execute(\\App\\Models\\Account::findOrFail(%d), \\App\\Models\\Location::findOrFail(%d), \\App\\Models\\StudioCashEntry::DirectionIn, %d, now(), null, %s, sourceKey: %s);',
            $account->id,
            $location->id,
            $amountCents,
            var_export('Concurrent cash write.', true),
            var_export($sourceKey, true),
        );
    }

    private function refundCode(Account $account, CustomerPurchase $purchase, int $amountCents, string $idempotencyKey): string
    {
        return sprintf(
            'app(\\App\\Actions\\RecordCustomerPurchaseRefund::class)->execute(\\App\\Models\\Account::findOrFail(%d), \\App\\Models\\CustomerPurchase::findOrFail(%d), \\App\\Models\\CustomerPurchaseRefund::MethodCashless, null, %d, now(), null, %s, %s);',
            $account->id,
            $purchase->id,
            $amountCents,
            var_export('Concurrent partial refund.', true),
            var_export($idempotencyKey, true),
        );
    }

    private function voidExpenseCode(Account $account, StudioExpense $expense, User $owner): string
    {
        return sprintf(
            'app(\\App\\Actions\\VoidStudioExpense::class)->execute(\\App\\Models\\Account::findOrFail(%d), \\App\\Models\\StudioExpense::findOrFail(%d), \\App\\Models\\User::findOrFail(%d), %s);',
            $account->id,
            $expense->id,
            $owner->id,
            var_export('Concurrent duplicate void.', true),
        );
    }

    private function customerPaymentLedgerCode(Account $account, Location $location, CustomerPurchase $purchase): string
    {
        return sprintf(
            'app(\\App\\Actions\\RecordStudioCashEntry::class)->execute(\\App\\Models\\Account::findOrFail(%d), \\App\\Models\\Location::findOrFail(%d), \\App\\Models\\StudioCashEntry::DirectionIn, 1000, now(), null, %s, \\App\\Models\\StudioCashEntry::PurposeCustomerPayment, purchase: \\App\\Models\\CustomerPurchase::findOrFail(%d), sourceKey: %s);',
            $account->id,
            $location->id,
            var_export('Concurrent customer payment.', true),
            $purchase->id,
            var_export('concurrency:purchase:'.$purchase->id, true),
        );
    }

    private function reconciliationCode(Account $account, Location $location, User $owner): string
    {
        return sprintf(
            'app(\\App\\Actions\\ReconcileCashbox::class)->execute(\\App\\Models\\Account::findOrFail(%d), \\App\\Models\\Location::findOrFail(%d), 5000, \\App\\Models\\User::findOrFail(%d), %s, %s);',
            $account->id,
            $location->id,
            $owner->id,
            var_export('Concurrent physical count.', true),
            var_export((string) Str::uuid(), true),
        );
    }

    private function epochCode(Account $account, Location $location, User $owner, string $idempotencyKey): string
    {
        return sprintf(
            'app(\\App\\Actions\\StartFinanceEpoch::class)->execute(\\App\\Models\\Account::findOrFail(%d), [[%s => %d, %s => %s, %s => 5000]], \\App\\Models\\User::findOrFail(%d), %s, %s);',
            $account->id,
            var_export('location_id', true),
            $location->id,
            var_export('currency', true),
            var_export('UAH', true),
            var_export('actual_counted_cents', true),
            $owner->id,
            var_export('Concurrent epoch start.', true),
            var_export($idempotencyKey, true),
        );
    }

    private function payrollCloseCode(Account $account, User $owner, string $startsOn, string $endsOn, string $idempotencyKey): string
    {
        return sprintf(
            'app(\\App\\Actions\\ClosePayrollRun::class)->execute(\\App\\Models\\Account::findOrFail(%d), \\App\\Models\\User::findOrFail(%d), \\Carbon\\CarbonImmutable::parse(%s), \\Carbon\\CarbonImmutable::parse(%s), %s);',
            $account->id,
            $owner->id,
            var_export($startsOn, true),
            var_export($endsOn, true),
            var_export($idempotencyKey, true),
        );
    }
}
