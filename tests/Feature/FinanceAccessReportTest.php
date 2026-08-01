<?php

namespace Tests\Feature;

use App\Actions\RecordStudioCashEntry;
use App\Enums\AccountApiTokenAbility;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\ExpenseCategory;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\Trainer;
use App\Models\User;
use App\Support\AccountApiTokenAbilityAuthorizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FinanceAccessReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_finance_permissions_separate_cash_payments_reports_and_payroll(): void
    {
        $owner = User::factory()->create();
        $cashManager = User::factory()->create();
        $trainer = User::factory()->create();
        $reporter = User::factory()->create();
        $payrollManager = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $account->users()->syncWithoutDetaching([
            $cashManager->id => ['role' => AccountRole::Trainer->value, 'permissions' => [StudioPermission::ManageStudioCashflow->value]],
            $trainer->id => ['role' => AccountRole::Trainer->value, 'permissions' => null],
            $reporter->id => ['role' => AccountRole::Trainer->value, 'permissions' => [StudioPermission::ViewStudioFinancialReports->value]],
            $payrollManager->id => ['role' => AccountRole::Trainer->value, 'permissions' => [StudioPermission::ManageStudioPayroll->value]],
        ]);
        Location::factory()->for($account)->create();

        $this->actingAs($cashManager)
            ->get(route('dashboard.accounts.cash.index', $account))
            ->assertOk();
        $this->actingAs($cashManager)
            ->get(route('dashboard.accounts.expenses.index', $account))
            ->assertOk();
        $this->actingAs($cashManager)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertForbidden();
        $this->actingAs($cashManager)
            ->get(route('dashboard.accounts.reports.financial', $account))
            ->assertForbidden();
        $this->actingAs($cashManager)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertForbidden();
        $this->actingAs($cashManager)
            ->get(route('dashboard.accounts.payroll.index', $account))
            ->assertForbidden();

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.cash.index', $account))
            ->assertForbidden();
        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertForbidden();

        $this->actingAs($reporter)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk();
        $this->actingAs($reporter)
            ->get(route('dashboard.accounts.reports.financial', $account))
            ->assertOk();
        $this->actingAs($reporter)
            ->get(route('dashboard.accounts.reports.earnings', $account))
            ->assertOk();
        $this->actingAs($reporter)
            ->get(route('dashboard.accounts.reports.rentals', $account))
            ->assertOk();
        $this->actingAs($reporter)
            ->get(route('dashboard.accounts.reports.index', $account))
            ->assertOk()
            ->assertSee(__('app.financial_reports'))
            ->assertSee(route('dashboard.accounts.reports.financial', $account), false)
            ->assertSee(route('dashboard.accounts.reports.earnings', $account), false)
            ->assertSee(route('dashboard.accounts.reports.rentals', $account), false)
            ->assertDontSee(__('app.operational_reports'));
        $this->actingAs($reporter)
            ->get(route('dashboard.accounts.cash.index', $account))
            ->assertForbidden();

        $this->actingAs($payrollManager)
            ->get(route('dashboard.accounts.payroll.index', $account))
            ->assertOk();
        $this->actingAs($payrollManager)
            ->get(route('dashboard.accounts.reports.financial', $account))
            ->assertForbidden();
    }

    public function test_default_trainer_can_record_one_cash_class_payment_without_finance_page_access(): void
    {
        $owner = User::factory()->create();
        $trainerUser = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $account->users()->syncWithoutDetaching([
            $trainerUser->id => ['role' => AccountRole::Trainer->value, 'permissions' => null],
        ]);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($trainer)
            ->for($classType)
            ->create();
        $customer = Customer::factory()->for($account)->create();
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'booked_by_user_id' => $owner->id,
                'status' => ClassBookingStatus::Booked->value,
            ]);

        $this->actingAs($trainerUser)
            ->post(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '125.00',
            ])
            ->assertRedirect();

        $payment = $booking->manualCashPayment()->sole();
        $this->assertSame(12500, $payment->amount_cents);
        $this->assertSame($location->id, $payment->location_id);
        $this->assertDatabaseHas('studio_cash_entries', [
            'customer_purchase_id' => $payment->id,
            'location_id' => $location->id,
            'amount_cents' => 12500,
        ]);
        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertForbidden();
    }

    public function test_financial_report_separates_operating_result_from_owner_cash_movements(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        FinanceEpoch::factory()->for($account)->create(['starts_at' => now()->subDay(), 'is_legacy' => false]);
        $customer = Customer::factory()->for($account)->create();
        $purchase = CustomerPurchase::factory()->for($account)->for($customer)->for($location)->create([
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => 10000,
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);
        CustomerPurchaseRefund::factory()->for($account)->for($purchase, 'customerPurchase')->for($location)->create([
            'method' => CustomerPurchaseRefund::MethodCashless,
            'amount_cents' => 1000,
            'currency' => 'UAH',
            'refunded_at' => now(),
        ]);
        $category = ExpenseCategory::factory()->for($account)->create();
        StudioExpense::factory()->for($account)->for($category, 'category')->create([
            'location_id' => $location->id,
            'expense_location_id' => $location->id,
            'payment_method' => StudioExpense::PaymentMethodBankTransfer,
            'amount_cents' => 2000,
            'currency' => 'UAH',
            'occurred_at' => now(),
        ]);
        app(RecordStudioCashEntry::class)->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            5000,
            now(),
            $owner,
            'Owner added change cash.',
            sourceKey: 'test:owner:deposit',
        );

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.financial', $account))
            ->assertOk();
        $totals = $response->viewData('report')['totals'];

        $this->assertSame(['UAH' => 10000], $totals['payments']);
        $this->assertSame(['UAH' => 1000], $totals['refunds']);
        $this->assertSame(['UAH' => 2000], $totals['expenses']);
        $this->assertSame(['UAH' => 5000], $totals['owner_deposits']);
        $this->assertSame(['UAH' => 7000], $totals['operating_cash_result']);
    }

    public function test_rental_report_exposes_accrued_paid_refunded_debt_and_status(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::RoomRental->value]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($trainer)->for($classType)->create([
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);
        $customer = Customer::factory()->for($account)->create();
        $booking = ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create([
            'booked_by_user_id' => $owner->id,
            'status' => ClassBookingStatus::Attended->value,
            'skip_class_pass_reservation' => true,
        ]);
        CustomerPurchase::factory()->for($account)->for($customer)->for($location)->create([
            'class_booking_id' => $booking->id,
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashBooking,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => 6000,
            'currency' => 'UAH',
            'paid_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.reports.rentals', $account))
            ->assertOk();
        $report = $response->viewData('report');

        $this->assertCount(1, $report['rows']);
        $this->assertSame(['UAH' => 6000], $report['totals']['accrued']);
        $this->assertSame(['UAH' => 6000], $report['totals']['paid']);
        $this->assertSame(['UAH' => 0], $report['totals']['debt']);
        $this->assertSame('paid', $report['rows']->sole()['status']);
    }

    public function test_mcp_finance_read_uses_financial_report_permission(): void
    {
        $this->assertSame(
            StudioPermission::ViewStudioFinancialReports,
            app(AccountApiTokenAbilityAuthorizer::class)->requiredPermission(AccountApiTokenAbility::McpPaymentsRead),
        );
    }
}
