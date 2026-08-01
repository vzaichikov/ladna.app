<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\PayrollCadence;
use App\Models\Account;
use App\Models\CashboxReconciliation;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\ExpenseCategory;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\McpToolInvocation;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\Trainer;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinanceMcpTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_financial_report_is_epoch_clamped_tenant_scoped_and_keeps_owner_movements_out_of_result(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_currency' => 'UAH']);
        $location = Location::factory()->for($account)->create(['name' => 'Main']);
        $epoch = FinanceEpoch::factory()->for($account)->create(['starts_at' => '2026-07-10 12:00:00']);
        $customer = Customer::factory()->for($account)->create(['name' => 'Report Customer']);
        $purchase = $this->purchase($account, $customer, $location, 10000, '2026-07-11 10:00:00');
        $this->purchase($account, $customer, $location, 90000, '2026-07-10 11:59:59');
        CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->for($location)
            ->create([
                'amount_cents' => 2000,
                'currency' => 'UAH',
                'refunded_at' => '2026-07-12 10:00:00',
                'reason' => 'private refund note secret-123',
            ]);
        $category = ExpenseCategory::factory()->for($account)->create(['name' => 'Operations']);
        StudioExpense::factory()->for($account)->for($category, 'category')->create([
            'expense_location_id' => $location->id,
            'amount_cents' => 3000,
            'currency' => 'UAH',
            'occurred_at' => '2026-07-13 10:00:00',
            'reason' => 'bank credential secret-456',
        ]);
        $this->cashEntry($account, $epoch, $location, StudioCashEntry::DirectionIn, StudioCashEntry::PurposeDeposit, 7000);
        $this->cashEntry($account, $epoch, $location, StudioCashEntry::DirectionOut, StudioCashEntry::PurposeOwnerWithdrawal, 1000);

        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();
        $this->purchase($otherAccount, $otherCustomer, $otherLocation, 999999, '2026-07-15 10:00:00');

        $token = app(AccountApiTokenIssuer::class)->issue($account, 'Finance reports', [
            AccountApiTokenAbility::McpPaymentsRead,
        ]);

        $response = $this->withToken($token->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-financial-report', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.period.date_from', '2026-07-10')
            ->assertJsonPath('result.structuredContent.active_epoch.finance_epoch_id', $epoch->id)
            ->assertJsonPath('result.structuredContent.totals.payments.0.amount_cents', 10000)
            ->assertJsonPath('result.structuredContent.totals.refunds.0.amount_cents', 2000)
            ->assertJsonPath('result.structuredContent.totals.expenses.0.amount_cents', 3000)
            ->assertJsonPath('result.structuredContent.totals.owner_deposits.0.amount_cents', 7000)
            ->assertJsonPath('result.structuredContent.totals.owner_withdrawals.0.amount_cents', 1000)
            ->assertJsonPath('result.structuredContent.totals.operating_cash_result.0.amount_cents', 5000);

        $response
            ->assertDontSee('secret-123')
            ->assertDontSee('secret-456')
            ->assertDontSee('999999');

        $invocation = McpToolInvocation::query()
            ->where('account_api_token_id', $token->id)
            ->where('tool_name', 'get-financial-report')
            ->firstOrFail();

        $this->assertSame(['status' => 'ok'], $invocation->output);
    }

    public function test_cashbox_overview_uses_latest_actual_reconciliation_and_requires_cashflow_ability(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_currency' => 'UAH']);
        $location = Location::factory()->for($account)->create(['name' => 'Podil']);
        $epoch = FinanceEpoch::factory()->for($account)->create(['starts_at' => '2026-07-01 00:00:00']);
        $beforeCutoff = $this->cashEntry($account, $epoch, $location, StudioCashEntry::DirectionIn, StudioCashEntry::PurposeDeposit, 10000);
        $reconciliation = CashboxReconciliation::factory()
            ->for($account)
            ->for($epoch, 'financeEpoch')
            ->for($location)
            ->create([
                'cutoff_cash_entry_id' => $beforeCutoff->id,
                'currency' => 'UAH',
                'expected_before_cents' => 10000,
                'actual_counted_cents' => 8000,
                'variance_cents' => -2000,
                'occurred_at' => '2026-07-20 10:00:00',
                'reason' => 'private count reason',
            ]);
        $this->cashEntry($account, $epoch, $location, StudioCashEntry::DirectionIn, StudioCashEntry::PurposeCustomerPayment, 1000);
        $this->cashEntry($account, $epoch, $location, StudioCashEntry::DirectionOut, StudioCashEntry::PurposeOperationalExpense, 500);
        $this->cashEntry($account, $epoch, $location, StudioCashEntry::DirectionIn, StudioCashEntry::PurposeDeposit, 250, 'USD');

        $allowedToken = app(AccountApiTokenIssuer::class)->issue($account, 'Cashboxes', [
            AccountApiTokenAbility::McpCashflowRead,
        ]);
        $reportToken = app(AccountApiTokenIssuer::class)->issue($account, 'Reports only', [
            AccountApiTokenAbility::McpPaymentsRead,
        ]);

        $response = $this->withToken($allowedToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-cashbox-overview'))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.cashboxes.0.location.location_id', $location->id)
            ->assertJsonPath('result.structuredContent.cashboxes.0.balance.amount_cents', 8500)
            ->assertJsonPath('result.structuredContent.cashboxes.0.baseline_actual.amount_cents', 8000)
            ->assertJsonPath('result.structuredContent.cashboxes.0.movements_after_baseline.amount_cents', 500)
            ->assertJsonPath('result.structuredContent.cashboxes.0.latest_reconciliation.cashbox_reconciliation_id', $reconciliation->id)
            ->assertJsonPath('result.structuredContent.cashboxes.0.latest_reconciliation.variance.amount_cents', -2000)
            ->assertJsonPath('result.structuredContent.cashboxes.1.currency', 'USD')
            ->assertJsonPath('result.structuredContent.cashboxes.1.balance.amount_cents', 250);

        $response->assertDontSee('private count reason');

        $this->withToken($reportToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-cashbox-overview'))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('app.api_token_forbidden'));

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_api_token_id' => $reportToken->id,
            'tool_name' => 'get-cashbox-overview',
            'required_ability' => AccountApiTokenAbility::McpCashflowRead->value,
            'status' => 'denied',
        ]);
    }

    public function test_report_and_payroll_tools_have_separate_abilities_and_return_bounded_immutable_snapshots(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Biweekly,
            'payroll_anchor_date' => '2026-07-06',
        ]);
        FinanceEpoch::factory()->for($account)->create(['starts_at' => '2026-07-01 00:00:00']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Payroll Trainer']);
        $run = PayrollRun::factory()->for($account)->create([
            'cadence' => PayrollCadence::Biweekly,
            'period_starts_on' => '2026-07-06',
            'period_ends_on' => '2026-07-19',
            'totals' => ['UAH' => 12345],
            'void_reason' => 'private payroll reason',
        ]);
        PayrollRunLine::factory()
            ->for($account)
            ->for($run, 'payrollRun')
            ->for($trainer)
            ->create([
                'amounts' => ['UAH' => 12345],
                'entries' => [['private' => 'sensitive salary evidence']],
            ]);
        $reportToken = app(AccountApiTokenIssuer::class)->issue($account, 'Reports', [
            AccountApiTokenAbility::McpPaymentsRead,
        ]);
        $payrollToken = app(AccountApiTokenIssuer::class)->issue($account, 'Payroll', [
            AccountApiTokenAbility::McpPayrollRead,
        ]);

        foreach (['get-financial-report', 'get-earnings-report', 'get-rental-report'] as $toolName) {
            $this->withToken($reportToken->tokenValue())
                ->postJson('/mcp/ladna-studio', $this->toolPayload($toolName, [
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                    'limit' => 1,
                ]))
                ->assertOk()
                ->assertJsonPath('result.structuredContent.status', 'ok');
        }

        $payrollResponse = $this->withToken($payrollToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-payroll-overview', ['limit' => 1]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.cadence.value', PayrollCadence::Biweekly->value)
            ->assertJsonPath('result.structuredContent.cadence.anchor_date', '2026-07-06')
            ->assertJsonPath('result.structuredContent.runs.0.payroll_run_id', $run->id)
            ->assertJsonPath('result.structuredContent.runs.0.immutable_snapshot', true)
            ->assertJsonPath('result.structuredContent.runs.0.totals.0.amount_cents', 12345)
            ->assertJsonPath('result.structuredContent.runs.0.lines.0.trainer_name', 'Payroll Trainer');

        $payrollResponse
            ->assertDontSee('private payroll reason')
            ->assertDontSee('sensitive salary evidence');

        $this->withToken($reportToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-payroll-overview'))
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $this->withToken($payrollToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-financial-report'))
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_finance_tools_are_advertised_without_tenant_arguments(): void
    {
        $account = Account::factory()->create();
        $token = app(AccountApiTokenIssuer::class)->issue($account, 'Schema', [
            AccountApiTokenAbility::McpRead,
        ]);

        $response = $this->withToken($token->tokenValue())
            ->postJson('/mcp/ladna-studio', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => ['per_page' => 50],
            ])
            ->assertOk();
        $tools = collect($response->json('result.tools'))->keyBy('name');

        foreach ([
            'get-financial-report',
            'get-cashbox-overview',
            'get-earnings-report',
            'get-rental-report',
            'get-payroll-overview',
        ] as $toolName) {
            $this->assertTrue($tools->has($toolName));
            $this->assertArrayNotHasKey('account_id', $tools[$toolName]['inputSchema']['properties']);
            $this->assertArrayNotHasKey('tenant_id', $tools[$toolName]['inputSchema']['properties']);
        }

        $this->assertSame(50, data_get($tools, 'get-financial-report.inputSchema.properties.limit.maximum'));
        $this->assertSame(3, data_get($tools, 'get-cashbox-overview.inputSchema.properties.currency.minLength'));
    }

    private function purchase(
        Account $account,
        Customer $customer,
        Location $location,
        int $amountCents,
        string $paidAt,
    ): CustomerPurchase {
        return CustomerPurchase::factory()->for($account)->for($customer)->for($location)->create([
            'class_pass_plan_id' => null,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => $amountCents,
            'currency' => 'UAH',
            'paid_at' => $paidAt,
        ]);
    }

    private function cashEntry(
        Account $account,
        FinanceEpoch $epoch,
        Location $location,
        string $direction,
        string $purpose,
        int $amountCents,
        string $currency = 'UAH',
    ): StudioCashEntry {
        return StudioCashEntry::factory()
            ->for($account)
            ->for($epoch, 'financeEpoch')
            ->for($location)
            ->create([
                'direction' => $direction,
                'purpose' => $purpose,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'occurred_at' => '2026-07-21 10:00:00',
            ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolPayload(string $name, array $arguments = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }
}
