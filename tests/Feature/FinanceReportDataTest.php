<?php

namespace Tests\Feature;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\ExpenseCategory;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use App\Support\Finance\FinanceReportData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinanceReportDataTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_keeps_currencies_separate_and_owner_movements_out_of_operating_result(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'UTC'));

        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['name' => 'Main']);
        $previousEpoch = FinanceEpoch::factory()->for($account)->create([
            'starts_at' => '2026-06-01 00:00:00',
            'reason' => 'Previous epoch',
        ]);
        $activeEpoch = FinanceEpoch::factory()->for($account)->create([
            'starts_at' => '2026-07-01 00:00:00',
            'reason' => 'Current epoch',
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Report Customer']);

        $uahPurchase = $this->purchase($account, $customer, $location, 10000, 'UAH', '2026-07-10 10:00:00');
        $this->purchase($account, $customer, $location, 2000, 'USD', '2026-07-11 10:00:00');
        CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($uahPurchase, 'customerPurchase')
            ->for($location)
            ->create([
                'amount_cents' => 1000,
                'currency' => 'UAH',
                'refunded_at' => '2026-07-12 10:00:00',
            ]);

        $paidEvent = Event::factory()->for($account)->for($location)->create(['title' => 'Paid event']);
        EventOrder::factory()->for($account)->for($paidEvent)->create([
            'status' => EventOrderStatus::Paid->value,
            'amount_cents' => 3000,
            'currency' => 'UAH',
            'paid_at' => '2026-07-13 10:00:00',
        ]);
        $refundedEvent = Event::factory()->for($account)->for($location)->create(['title' => 'Refunded event']);
        EventOrder::factory()->for($account)->for($refundedEvent)->create([
            'status' => EventOrderStatus::Refunded->value,
            'amount_cents' => 700,
            'currency' => 'USD',
            'paid_at' => '2026-07-14 10:00:00',
            'refunded_at' => '2026-07-15 10:00:00',
        ]);

        $category = ExpenseCategory::factory()->for($account)->create(['name' => 'Operations']);
        $this->expense($account, $category, $location, 2000, 'UAH', '2026-07-16 10:00:00');
        $this->expense($account, $category, $location, 500, 'USD', '2026-07-17 10:00:00');
        $this->expense($account, $category, $location, 9000, 'UAH', '2026-07-18 10:00:00', voided: true);

        $this->cashEntry($account, $activeEpoch, $location, StudioCashEntry::PurposeDeposit, 5000, 'UAH');
        $this->cashEntry($account, $activeEpoch, $location, StudioCashEntry::PurposeOwnerWithdrawal, 300, 'USD');
        $this->cashEntry($account, $previousEpoch, $location, StudioCashEntry::PurposeDeposit, 9900, 'UAH');

        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();
        $this->purchase($otherAccount, $otherCustomer, $otherLocation, 7777, 'UAH', '2026-07-10 10:00:00');

        $report = $this->report(
            $account,
            Carbon::parse('2026-07-01 00:00:00', 'UTC'),
            Carbon::parse('2026-07-31 23:59:59', 'UTC'),
            $activeEpoch,
        );

        $this->assertSame(['UAH' => 13000, 'USD' => 2700], $report['totals']['payments']);
        $this->assertSame(['UAH' => 1000, 'USD' => 700], $report['totals']['refunds']);
        $this->assertSame(['UAH' => 2000, 'USD' => 500], $report['totals']['expenses']);
        $this->assertSame(['UAH' => 5000], $report['totals']['owner_deposits']);
        $this->assertSame(['USD' => 300], $report['totals']['owner_withdrawals']);
        $this->assertSame(['UAH' => 10000, 'USD' => 1500], $report['totals']['operating_cash_result']);
        $this->assertCount(4, $report['sections']['payments']);
        $this->assertCount(2, $report['sections']['refunds']);
        $this->assertCount(2, $report['sections']['expenses']);
        $this->assertCount(1, $report['sections']['owner_deposits']);
        $this->assertCount(1, $report['sections']['owner_withdrawals']);
    }

    public function test_report_honours_exact_epoch_period_and_operational_location_boundaries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'UTC'));

        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $main = Location::factory()->for($account)->create(['name' => 'Main']);
        $secondary = Location::factory()->for($account)->create(['name' => 'Secondary']);
        $previousEpoch = FinanceEpoch::factory()->for($account)->create(['starts_at' => '2026-07-01 00:00:00']);
        $activeEpoch = FinanceEpoch::factory()->for($account)->create(['starts_at' => '2026-07-10 12:00:00']);
        $customer = Customer::factory()->for($account)->create();
        $startsAt = Carbon::parse('2026-07-10 12:00:00', 'UTC');
        $endsAt = Carbon::parse('2026-07-10 15:00:00', 'UTC');

        $this->purchase($account, $customer, $main, 9000, 'UAH', '2026-07-10 11:59:59');
        $this->purchase($account, $customer, $main, 100, 'UAH', '2026-07-10 12:00:00');
        $this->purchase($account, $customer, $main, 200, 'UAH', '2026-07-10 15:00:00');
        $this->purchase($account, $customer, $main, 8000, 'UAH', '2026-07-10 15:00:01');
        $this->purchase($account, $customer, $secondary, 7000, 'UAH', '2026-07-10 13:00:00');

        $mainEvent = Event::factory()->for($account)->for($main)->create();
        EventOrder::factory()->for($account)->for($mainEvent)->create([
            'status' => EventOrderStatus::Paid->value,
            'amount_cents' => 300,
            'currency' => 'UAH',
            'paid_at' => '2026-07-10 14:00:00',
        ]);
        $secondaryEvent = Event::factory()->for($account)->for($secondary)->create();
        EventOrder::factory()->for($account)->for($secondaryEvent)->create([
            'status' => EventOrderStatus::Paid->value,
            'amount_cents' => 6000,
            'currency' => 'UAH',
            'paid_at' => '2026-07-10 14:00:00',
        ]);

        $category = ExpenseCategory::factory()->for($account)->create();
        StudioExpense::factory()->for($account)->for($category, 'category')->create([
            'location_id' => $secondary->id,
            'expense_location_id' => $main->id,
            'cash_location_id' => $secondary->id,
            'amount_cents' => 50,
            'currency' => 'UAH',
            'occurred_at' => '2026-07-10 12:05:00',
        ]);
        StudioExpense::factory()->for($account)->for($category, 'category')->create([
            'location_id' => $main->id,
            'expense_location_id' => $secondary->id,
            'cash_location_id' => $main->id,
            'amount_cents' => 5000,
            'currency' => 'UAH',
            'occurred_at' => '2026-07-10 12:05:00',
        ]);

        $this->cashEntry($account, $activeEpoch, $main, StudioCashEntry::PurposeDeposit, 70, 'UAH', '2026-07-10 12:00:00');
        $this->cashEntry($account, $activeEpoch, $secondary, StudioCashEntry::PurposeDeposit, 7000, 'UAH', '2026-07-10 12:30:00');
        $this->cashEntry($account, $previousEpoch, $main, StudioCashEntry::PurposeDeposit, 9000, 'UAH', '2026-07-10 12:30:00');

        $report = $this->report($account, $startsAt, $endsAt, $activeEpoch, $main);

        $this->assertSame(['UAH' => 600], $report['totals']['payments']);
        $this->assertSame([], $report['totals']['refunds']);
        $this->assertSame(['UAH' => 50], $report['totals']['expenses']);
        $this->assertSame(['UAH' => 70], $report['totals']['owner_deposits']);
        $this->assertSame(['UAH' => 550], $report['totals']['operating_cash_result']);
        $this->assertSame(['Main', null, 'Main'], $report['sections']['payments']->pluck('location')->all());
        $this->assertSame('Main', $report['sections']['expenses']->sole()['location']);
    }

    public function test_financial_route_clamps_requested_period_to_intraday_active_epoch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'Europe/Kyiv']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $epoch = FinanceEpoch::factory()->for($account)->create(['starts_at' => '2026-07-10 12:30:00']);
        $customer = Customer::factory()->for($account)->create();
        $this->purchase($account, $customer, $location, 100, 'UAH', '2026-07-10 12:29:59');
        $this->purchase($account, $customer, $location, 200, 'UAH', '2026-07-10 12:30:00');

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.reports.financial', [
            'account' => $account,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-10',
        ]));

        $response->assertOk();
        $this->assertSame($epoch->id, $response->viewData('epoch')->id);
        $this->assertSame('2026-07-10', $response->viewData('filters')['date_from']);
        $this->assertSame(['UAH' => 200], $response->viewData('report')['totals']['payments']);
    }

    private function purchase(
        Account $account,
        Customer $customer,
        Location $location,
        int $amountCents,
        string $currency,
        string $paidAt,
    ): CustomerPurchase {
        return CustomerPurchase::factory()->for($account)->for($customer)->for($location)->create([
            'class_pass_plan_id' => null,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'paid_at' => $paidAt,
        ]);
    }

    private function expense(
        Account $account,
        ExpenseCategory $category,
        Location $location,
        int $amountCents,
        string $currency,
        string $occurredAt,
        bool $voided = false,
    ): StudioExpense {
        return StudioExpense::factory()->for($account)->for($category, 'category')->create([
            'location_id' => $location->id,
            'expense_location_id' => $location->id,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'occurred_at' => $occurredAt,
            'voided_at' => $voided ? Carbon::parse($occurredAt, 'UTC')->addHour() : null,
        ]);
    }

    private function cashEntry(
        Account $account,
        FinanceEpoch $epoch,
        Location $location,
        string $purpose,
        int $amountCents,
        string $currency,
        string $occurredAt = '2026-07-20 10:00:00',
    ): StudioCashEntry {
        return StudioCashEntry::factory()->for($account)->for($location)->create([
            'finance_epoch_id' => $epoch->id,
            'direction' => $purpose === StudioCashEntry::PurposeOwnerWithdrawal
                ? StudioCashEntry::DirectionOut
                : StudioCashEntry::DirectionIn,
            'purpose' => $purpose,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(
        Account $account,
        Carbon $startsAt,
        Carbon $endsAt,
        ?FinanceEpoch $epoch,
        ?Location $location = null,
    ): array {
        return app(FinanceReportData::class)->forAccount(
            $account,
            [
                'date_from' => $startsAt->toDateString(),
                'date_to' => $endsAt->toDateString(),
                'location_id' => $location?->id,
            ],
            $startsAt,
            $endsAt,
            $epoch,
        );
    }
}
