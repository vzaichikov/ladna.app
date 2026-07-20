<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\RecordManualCustomerClassPassPayment;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\ExpenseCategory;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentHistoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_owner_can_view_customer_payment_history_with_fiscal_data_when_enabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio A', 'timezone' => 'America/New_York']);
        $account->addOwner($owner);
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account);
        $purchase->update(['paid_at' => Carbon::parse('2026-06-20 02:30:00', 'UTC')]);
        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($purchase, 'payment')
            ->fiscalized('FN-OWNER-1')
            ->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertSee('Group 8 classes')
            ->assertSee('Payment Client')
            ->assertSee('2026-06-19 22:30')
            ->assertDontSee('2026-06-20 02:30')
            ->assertSee('FN-OWNER-1');
    }

    public function test_studio_owner_payment_history_hides_fiscal_data_when_ladna_fiscalization_is_disabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio B']);
        $account->addOwner($owner);
        $purchase = $this->customerPurchase($account);
        $purchase->update(['paid_at' => Carbon::parse('2026-06-20 02:30:00', 'UTC')]);
        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($purchase, 'payment')
            ->fiscalized('FN-HIDDEN-1')
            ->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertSee('Group 8 classes')
            ->assertDontSee('FN-HIDDEN-1');
    }

    public function test_studio_cash_class_pass_payment_uses_cash_label_location_and_no_pending_fiscal_status(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio Cash']);
        $account->addOwner($owner);
        $this->enableAccountFiscalization($account);
        $location = Location::factory()->for($account)->create(['name' => 'Podil cash desk']);

        $this->customerPurchase($account, $location, [
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee(__('app.provider_studio_cash'))
            ->assertSee('Podil cash desk')
            ->assertSee(__('app.manual_cash_not_fiscalized'))
            ->assertDontSee(__('app.fiscal_status_pending'));
    }

    public function test_studio_cash_booking_payment_appears_in_schedule_and_payment_history_without_fiscal_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio Rent Cash', 'default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $this->enableAccountFiscalization($account);
        $location = Location::factory()->for($account)->create(['name' => 'Main desk', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Small Hall']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Room rental',
            'schedule_kind' => ScheduleKind::RoomRental->value,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Room rental',
                'starts_at' => '2026-06-23 10:00:00',
                'ends_at' => '2026-06-23 11:00:00',
            ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Rent Client']);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create(['skip_class_pass_reservation' => true]);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '350.50',
            ])
            ->assertOk()
            ->assertJsonPath('message', __('app.class_booking_payment_recorded'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);

        $this->assertStringContainsString(__('app.class_booking_payment'), $response->json('card_html'));
        $this->assertStringContainsString(MoneyFormatter::format(35050, 'UAH'), $response->json('card_html'));

        $payment = CustomerPurchase::whereBelongsTo($account)->where('class_booking_id', $booking->id)->firstOrFail();

        $this->assertSame(CustomerPurchase::ProviderStudioCash, $payment->provider);
        $this->assertSame(CustomerPurchase::SourceManualCashBooking, $payment->payment_source);
        $this->assertSame(CustomerPurchaseStatus::PaymentPaid, $payment->status);
        $this->assertSame(35050, $payment->amount_cents);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee('Room rental')
            ->assertSee('Rent Client')
            ->assertSee('Small Hall')
            ->assertSee(__('app.booking'))
            ->assertSee(__('app.provider_studio_cash'))
            ->assertSee(__('app.manual_cash_not_fiscalized'))
            ->assertDontSee(__('app.fiscal_status_pending'));

        Carbon::setTestNow();
    }

    public function test_any_time_addon_booking_payment_is_recorded_against_booking_and_class_pass(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio Any Time Cash', 'default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main desk', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Main Hall']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Pole group',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Evening Pole',
                'starts_at' => '2026-06-23 18:00:00',
                'ends_at' => '2026-06-23 19:00:00',
            ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Any Time Client']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Morning with add-on',
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'sessions_count' => 4,
            'available_from_time' => null,
            'available_until_time' => '12:00:00',
            'allows_any_time' => true,
            'any_time_addon_price_cents' => 4500,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $bookingResponse = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
            ])
            ->assertCreated();

        $this->assertStringContainsString(__('app.any_time_addon_due'), $bookingResponse->json('card_html'));
        $this->assertStringContainsString(MoneyFormatter::format(4500, 'UAH'), $bookingResponse->json('card_html'));

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '40.00',
            ])
            ->assertUnprocessable();

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '45.00',
            ])
            ->assertOk()
            ->assertJsonPath('message', __('app.class_booking_payment_recorded'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);

        $this->assertStringContainsString(__('app.any_time_addon_paid'), $response->json('card_html'));
        $this->assertStringContainsString(MoneyFormatter::format(4500, 'UAH'), $response->json('card_html'));

        $payment = CustomerPurchase::whereBelongsTo($account)->where('class_booking_id', $booking->id)->firstOrFail();

        $this->assertSame(CustomerPurchase::ProviderStudioCash, $payment->provider);
        $this->assertSame(CustomerPurchase::SourceManualCashBooking, $payment->payment_source);
        $this->assertSame(CustomerPurchaseStatus::PaymentPaid, $payment->status);
        $this->assertSame($customerClassPass->id, $payment->customer_class_pass_id);
        $this->assertSame($plan->id, $payment->class_pass_plan_id);
        $this->assertSame(4500, $payment->amount_cents);
        $this->assertSame(ScheduleKind::GroupClass->value, $payment->schedule_kind);
        $this->assertSame(0, $payment->sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->paid_amount_cents);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee(__('app.any_time_addon_payment'))
            ->assertSee('Evening Pole')
            ->assertSee('Any Time Client')
            ->assertSee($customerClassPass->code)
            ->assertSee(MoneyFormatter::format(4500, 'UAH'));

        Carbon::setTestNow();
    }

    public function test_regular_reserved_booking_still_blocks_manual_booking_payment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio Reserved Cash', 'default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'starts_at' => '2026-06-23 10:00:00',
                'ends_at' => '2026-06-23 11:00:00',
            ]);
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'sessions_count' => 1,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
            ])
            ->assertCreated();

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();
        $this->assertTrue($booking->classPassReservation()->exists());

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.bookings.payment.store', [$account, $booking]), [
                'amount' => '45.00',
            ])
            ->assertUnprocessable();

        $this->assertFalse(CustomerPurchase::whereBelongsTo($account)->where('class_booking_id', $booking->id)->exists());

        Carbon::setTestNow();
    }

    public function test_partial_cash_class_pass_payments_appear_as_separate_rows_and_sum_actual_cash(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio Partial', 'default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Main cash desk']);
        $customer = Customer::factory()->for($account)->create(['name' => 'Partial Client']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Partial plan',
            'price_cents' => 100000,
            'currency' => 'UAH',
        ]);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute(
            $account,
            $customer,
            $plan,
            issuedLocation: $location,
            paidAmountCents: 40000,
        );

        app(RecordManualCustomerClassPassPayment::class)->execute($account, $customerClassPass, $location, 60000);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee('Partial plan')
            ->assertSee('Partial Client')
            ->assertSee(MoneyFormatter::format(40000, 'UAH'))
            ->assertSee(MoneyFormatter::format(60000, 'UAH'))
            ->assertSee(MoneyFormatter::format(100000, 'UAH'))
            ->assertSee('Main cash desk');
    }

    public function test_studio_owner_can_filter_payment_history_by_location(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio Locations']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create(['name' => 'Center cash desk']);
        $secondLocation = Location::factory()->for($account)->create(['name' => 'Suburb cash desk']);
        $this->customerPurchase($account, $firstLocation, ['plan_name' => 'Center plan']);
        $this->customerPurchase($account, $secondLocation, ['plan_name' => 'Suburb plan']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [$account, 'location_id' => $firstLocation->id]))
            ->assertOk()
            ->assertSee('Center plan')
            ->assertSee('Center cash desk')
            ->assertDontSee('Suburb plan');
    }

    public function test_studio_payment_history_defaults_to_account_today_and_filters_by_effective_payment_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'Studio Periods', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Current paid purchase',
            'paid_at' => '2026-07-17 00:00:00',
            'started_at' => '2026-07-16 23:00:00',
        ]);
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Current started purchase',
            'status' => CustomerPurchaseStatus::PaymentPending->value,
            'paid_at' => null,
            'started_at' => '2026-07-17 09:00:00',
        ]);
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Previous purchase',
            'paid_at' => '2026-07-16 23:59:59',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertSee('name="date_from" type="date" value="2026-07-17"', false)
            ->assertSee('name="date_to" type="date" value="2026-07-17"', false)
            ->assertSee('Current paid purchase')
            ->assertSee('Current started purchase')
            ->assertDontSee('Previous purchase');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-16',
                'date_to' => '2026-07-16',
            ]))
            ->assertOk()
            ->assertSee('Previous purchase')
            ->assertDontSee('Current paid purchase')
            ->assertDontSee('Current started purchase');

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.payments.index', $account))
            ->followingRedirects()
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-18',
                'date_to' => '2026-07-17',
            ]))
            ->assertOk()
            ->assertSee('class="crm-help"', false);

        Carbon::setTestNow();
    }

    public function test_payment_period_totals_expenses_and_cash_balance_use_their_documented_scopes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'name' => 'Studio Period Totals',
            'timezone' => 'UTC',
            'default_currency' => 'UAH',
        ]);
        $account->addOwner($owner);
        $this->enableAccountFiscalization($account);
        $location = Location::factory()->for($account)->create();
        $paidPurchase = $this->customerPurchase($account, $location, [
            'plan_name' => 'Period paid purchase',
            'amount_cents' => 10000,
            'paid_at' => '2026-07-10 10:00:00',
        ]);
        $pendingPurchase = $this->customerPurchase($account, $location, [
            'plan_name' => 'Period pending purchase',
            'status' => CustomerPurchaseStatus::PaymentPending->value,
            'amount_cents' => 20000,
            'paid_at' => null,
            'started_at' => '2026-07-11 10:00:00',
        ]);
        $failedPurchase = $this->customerPurchase($account, $location, [
            'plan_name' => 'Period failed purchase',
            'status' => CustomerPurchaseStatus::PaymentFailed->value,
            'amount_cents' => 30000,
            'paid_at' => null,
            'started_at' => '2026-07-12 10:00:00',
        ]);
        $previousPurchase = $this->customerPurchase($account, $location, [
            'plan_name' => 'Previous paid purchase',
            'amount_cents' => 40000,
            'paid_at' => '2026-06-30 23:59:59',
        ]);
        FiscalReceipt::factory()->forAccountScope($account)->for($paidPurchase, 'payment')->failed()->create();
        FiscalReceipt::factory()->forAccountScope($account)->for($previousPurchase, 'payment')->failed()->create();

        $category = ExpenseCategory::factory()->for($account)->create(['name' => 'Supplies']);
        StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->create([
                'location_id' => $location->id,
                'amount_cents' => 2500,
                'occurred_at' => '2026-07-10 12:00:00',
                'payment_method' => StudioExpense::PaymentMethodBankCard,
            ]);
        StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->create([
                'location_id' => $location->id,
                'amount_cents' => 9000,
                'occurred_at' => '2026-07-11 12:00:00',
                'voided_at' => '2026-07-12 12:00:00',
                'void_reason' => 'Duplicate',
                'payment_method' => StudioExpense::PaymentMethodBankTransfer,
            ]);
        StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->create([
                'location_id' => $location->id,
                'amount_cents' => 8000,
                'occurred_at' => '2026-06-30 23:59:59',
            ]);
        StudioCashEntry::factory()->for($account)->for($location)->create([
            'direction' => StudioCashEntry::DirectionIn,
            'purpose' => StudioCashEntry::PurposeDeposit,
            'amount_cents' => 1000,
            'occurred_at' => '2026-06-30 10:00:00',
        ]);
        StudioCashEntry::factory()->for($account)->for($location)->create([
            'direction' => StudioCashEntry::DirectionOut,
            'purpose' => StudioCashEntry::PurposeOwnerWithdrawal,
            'amount_cents' => 300,
            'occurred_at' => '2026-07-13 10:00:00',
        ]);
        StudioCashEntry::factory()->for($account)->for($location)->create([
            'direction' => StudioCashEntry::DirectionOut,
            'purpose' => StudioCashEntry::PurposeOwnerWithdrawal,
            'amount_cents' => 200,
            'occurred_at' => '2026-07-14 10:00:00',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-17',
                'location_id' => $location->id,
            ]))
            ->assertOk();

        $this->assertSame(3, $response->viewData('stats')['total']);
        $this->assertSame(['UAH' => 10000], $response->viewData('stats')['paid_amounts_by_currency']);
        $this->assertSame(1, $response->viewData('stats')['pending']);
        $this->assertSame(1, $response->viewData('stats')['failed']);
        $this->assertSame(1, $response->viewData('stats')['fiscal_failed']);
        $this->assertSame(['UAH' => 500], $response->viewData('stats')['cash_balance_by_currency']);
        $this->assertSame([
            'income_by_currency' => ['UAH' => 10000],
            'expense_by_currency' => ['UAH' => 2500],
            'remaining_by_currency' => ['UAH' => 7000],
            'cash_received_by_currency' => [],
            'collection_by_currency' => ['UAH' => 500],
        ], $response->viewData('periodOverview'));
        $this->assertSame('Period failed purchase', $response->viewData('payments')->first()->plan_name);
        $this->assertCount(2, $response->viewData('expenses'));
        $this->assertSame(2500, $response->viewData('expenseCategoryBreakdown')->sole()['amount_cents']);

        $filteredResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-17',
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'expense_payment_method' => StudioExpense::PaymentMethodBankTransfer,
                'expense_status' => StudioExpense::StatusVoided,
            ]))
            ->assertOk();

        $this->assertSame(1, $filteredResponse->viewData('stats')['total']);
        $this->assertSame(1, $filteredResponse->viewData('stats')['pending']);
        $this->assertSame(1, $filteredResponse->viewData('stats')['failed']);
        $this->assertSame(1, $filteredResponse->viewData('stats')['fiscal_failed']);
        $this->assertSame($paidPurchase->id, $filteredResponse->viewData('payments')->sole()->id);
        $this->assertSame(9000, $filteredResponse->viewData('expenses')->sole()->amount_cents);
        $this->assertTrue($filteredResponse->viewData('expenseCategoryBreakdown')->isEmpty());
        $this->assertModelExists($pendingPurchase);
        $this->assertModelExists($failedPurchase);

        Carbon::setTestNow();
    }

    public function test_payment_and_cashflow_totals_keep_currencies_separate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'default_currency' => 'UAH',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $this->customerPurchase($account, $location, [
            'plan_name' => 'UAH payment',
            'amount_cents' => 10000,
            'currency' => 'UAH',
            'paid_at' => '2026-07-10 10:00:00',
        ]);
        $this->customerPurchase($account, $location, [
            'plan_name' => 'USD cash payment',
            'amount_cents' => 5000,
            'currency' => 'USD',
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'paid_at' => '2026-07-11 10:00:00',
        ]);
        $category = ExpenseCategory::factory()->for($account)->create();
        StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->create([
                'location_id' => $location->id,
                'amount_cents' => 2500,
                'currency' => 'UAH',
                'occurred_at' => '2026-07-12 10:00:00',
            ]);
        StudioCashEntry::factory()->for($account)->for($location)->create([
            'direction' => StudioCashEntry::DirectionIn,
            'purpose' => StudioCashEntry::PurposeDeposit,
            'amount_cents' => 1000,
            'currency' => 'UAH',
            'occurred_at' => '2026-07-13 10:00:00',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-17',
            ]))
            ->assertOk();

        $this->assertSame([
            'UAH' => 10000,
            'USD' => 5000,
        ], $response->viewData('stats')['paid_amounts_by_currency']);
        $this->assertSame([
            'UAH' => 1000,
            'USD' => 5000,
        ], $response->viewData('stats')['cash_balance_by_currency']);
        $this->assertSame([
            'UAH' => 7500,
            'USD' => 5000,
        ], $response->viewData('periodOverview')['remaining_by_currency']);
        $this->assertSame(['USD' => 5000], $response->viewData('periodOverview')['cash_received_by_currency']);

        Carbon::setTestNow();
    }

    public function test_studio_owner_can_search_payments_by_customer_name_and_phone(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $alicePurchase = $this->customerPurchase($account, $location, ['plan_name' => 'Alice plan']);
        $alicePurchase->customer->update(['name' => 'Alice Moon', 'phone' => '+380501112233']);
        $bobPurchase = $this->customerPurchase($account, $location, ['plan_name' => 'Bob plan']);
        $bobPurchase->customer->update(['name' => 'Bob Sun', 'phone' => '+380672224455']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'search' => 'Alice',
            ]))
            ->assertOk()
            ->assertSee('Alice plan')
            ->assertDontSee('Bob plan');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'search' => '672224455',
            ]))
            ->assertOk()
            ->assertSee('Bob plan')
            ->assertDontSee('Alice plan');
    }

    public function test_studio_owner_can_filter_cash_and_online_payment_methods(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Cash method plan',
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
        ]);
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Online method plan',
            'payment_source' => CustomerPurchase::SourceOnlineCheckout,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'payment_method' => CustomerPurchase::PaymentMethodCash,
            ]))
            ->assertOk()
            ->assertSee('Cash method plan')
            ->assertDontSee('Online method plan');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'payment_method' => CustomerPurchase::PaymentMethodOnline,
            ]))
            ->assertOk()
            ->assertSee('Online method plan')
            ->assertDontSee('Cash method plan');
    }

    public function test_payment_location_and_expense_filters_stay_scoped_to_their_own_sections(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_currency' => 'UAH']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create(['name' => 'First location']);
        $secondLocation = Location::factory()->for($account)->create(['name' => 'Second location']);
        $this->customerPurchase($account, $firstLocation, [
            'plan_name' => 'First location payment',
            'amount_cents' => 10000,
        ]);
        $this->customerPurchase($account, $secondLocation, [
            'plan_name' => 'Second location payment',
            'amount_cents' => 20000,
        ]);
        $firstCategory = ExpenseCategory::factory()->for($account)->create(['name' => 'First category']);
        $secondCategory = ExpenseCategory::factory()->for($account)->create(['name' => 'Second category']);
        StudioExpense::factory()->for($account)->for($firstCategory, 'category')->create([
            'location_id' => $firstLocation->id,
            'amount_cents' => 1000,
            'occurred_at' => now(),
        ]);
        StudioExpense::factory()->for($account)->for($secondCategory, 'category')->create([
            'location_id' => $secondLocation->id,
            'amount_cents' => 2000,
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'location_id' => $firstLocation->id,
                'expense_category_id' => $secondCategory->id,
            ]))
            ->assertOk()
            ->assertSee('First location payment')
            ->assertDontSee('Second location payment');

        $this->assertSame(30000, $response->viewData('periodOverview')['income_by_currency']['UAH']);
        $this->assertSame(3000, $response->viewData('periodOverview')['expense_by_currency']['UAH']);
        $this->assertSame($secondCategory->id, $response->viewData('expenses')->sole()->expense_category_id);
        $this->assertCount(2, $response->viewData('cashBalances'));

        Carbon::setTestNow();
    }

    public function test_cash_period_metrics_and_lifetime_balance_use_distinct_time_scopes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Today cash',
            'amount_cents' => 10000,
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'paid_at' => '2026-07-17 10:00:00',
        ]);
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Previous cash',
            'amount_cents' => 5000,
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'paid_at' => '2026-07-16 10:00:00',
        ]);
        StudioCashEntry::factory()->for($account)->for($location)->create([
            'direction' => StudioCashEntry::DirectionIn,
            'purpose' => StudioCashEntry::PurposeDeposit,
            'amount_cents' => 500,
            'occurred_at' => '2026-07-16 09:00:00',
        ]);
        StudioCashEntry::factory()->for($account)->for($location)->create([
            'direction' => StudioCashEntry::DirectionOut,
            'purpose' => StudioCashEntry::PurposeOwnerWithdrawal,
            'amount_cents' => 1000,
            'occurred_at' => '2026-07-16 11:00:00',
        ]);
        StudioCashEntry::factory()->for($account)->for($location)->create([
            'direction' => StudioCashEntry::DirectionOut,
            'purpose' => StudioCashEntry::PurposeOwnerWithdrawal,
            'amount_cents' => 3000,
            'occurred_at' => '2026-07-17 11:00:00',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk();

        $this->assertSame(['UAH' => 10000], $response->viewData('periodOverview')['cash_received_by_currency']);
        $this->assertSame(['UAH' => 3000], $response->viewData('periodOverview')['collection_by_currency']);
        $this->assertSame(['UAH' => 7000], $response->viewData('periodOverview')['remaining_by_currency']);
        $this->assertSame(['UAH' => 11500], $response->viewData('stats')['cash_balance_by_currency']);

        $previousDayResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-16',
                'date_to' => '2026-07-16',
            ]))
            ->assertOk();

        $this->assertSame(['UAH' => 5000], $previousDayResponse->viewData('periodOverview')['cash_received_by_currency']);
        $this->assertSame(['UAH' => 1000], $previousDayResponse->viewData('periodOverview')['collection_by_currency']);
        $this->assertSame(['UAH' => 11500], $previousDayResponse->viewData('stats')['cash_balance_by_currency']);

        Carbon::setTestNow();
    }

    public function test_payment_page_renders_sections_in_owner_task_order_and_hides_empty_attention(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $this->customerPurchase($account);

        $content = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk()
            ->assertDontSee(__('app.payment_pending_attention', ['count' => 0]))
            ->assertDontSee(__('app.payment_failed_attention', ['count' => 0]))
            ->getContent();

        $overviewPosition = strpos($content, 'data-payments-section="overview"');
        $historyPosition = strpos($content, 'data-payments-section="history"');
        $cashPosition = strpos($content, 'data-payments-section="cash"');
        $expensesPosition = strpos($content, 'data-payments-section="expenses"');

        $this->assertIsInt($overviewPosition);
        $this->assertIsInt($historyPosition);
        $this->assertIsInt($cashPosition);
        $this->assertIsInt($expensesPosition);
        $this->assertTrue($overviewPosition < $historyPosition);
        $this->assertTrue($historyPosition < $cashPosition);
        $this->assertTrue($cashPosition < $expensesPosition);
    }

    public function test_payment_period_boundaries_use_the_account_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'name' => 'New York Period Studio',
            'timezone' => 'America/New_York',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $this->customerPurchase($account, $location, [
            'plan_name' => 'Before local July',
            'paid_at' => '2026-07-01 03:59:59',
        ]);
        $this->customerPurchase($account, $location, [
            'plan_name' => 'At local July boundary',
            'paid_at' => '2026-07-01 04:00:00',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-01',
            ]))
            ->assertOk()
            ->assertSee('At local July boundary')
            ->assertDontSee('Before local July');

        Carbon::setTestNow();
    }

    public function test_platform_admin_can_view_saas_payment_history_with_fiscal_data_when_enabled(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->enablePlatformFiscalization();
        $account = Account::factory()->create(['name' => 'Studio Platform', 'timezone' => 'America/New_York']);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Studio Pro']);
        $payment = AccountSubscriptionPayment::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create([
                'payment_type' => AccountSubscriptionPaymentType::ManualRenewal->value,
                'status' => AccountSubscriptionPaymentStatus::PaymentPaid->value,
                'amount_cents' => 250000,
                'currency' => 'UAH',
                'paid_at' => Carbon::parse('2026-06-20 02:30:00', 'UTC'),
            ]);
        FiscalReceipt::factory()
            ->forPlatformScope($account)
            ->for($payment, 'payment')
            ->fiscalized('FN-PLATFORM-1')
            ->create();

        $this->actingAs($platformAdmin)
            ->get(route('platform.payments.index'))
            ->assertOk()
            ->assertSee('Studio Pro')
            ->assertSee('Studio Platform')
            ->assertSee('2026-06-19 22:30')
            ->assertDontSee('2026-06-20 02:30')
            ->assertSee('FN-PLATFORM-1');
    }

    public function test_non_owner_cannot_view_studio_payment_history(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customerPurchase(Account $account, ?Location $location = null, array $attributes = []): CustomerPurchase
    {
        $location ??= Location::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Payment Client',
            'phone' => '+38050'.fake()->unique()->numerify('######'),
        ]);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Group 8 classes',
            'price_cents' => 180000,
            'currency' => 'UAH',
            'sessions_count' => 8,
        ]);

        return CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'location_id' => $location->id,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'sessions_count' => $plan->sessions_count,
                'paid_at' => now(),
                ...$attributes,
            ]);
    }

    private function enableAccountFiscalization(Account $account): void
    {
        $this->enableFiscalizationSetting(IntegrationScope::Account, IntegrationProvider::LadnaFiscalization, $account);
        $this->enableFiscalizationSetting(IntegrationScope::Account, IntegrationProvider::Checkbox, $account, $this->checkboxCredentials());
    }

    private function enablePlatformFiscalization(): void
    {
        $this->enableFiscalizationSetting(IntegrationScope::Platform, IntegrationProvider::LadnaFiscalization);
        $this->enableFiscalizationSetting(IntegrationScope::Platform, IntegrationProvider::Checkbox, credentials: $this->checkboxCredentials());
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function enableFiscalizationSetting(
        IntegrationScope $scope,
        IntegrationProvider $provider,
        ?Account $account = null,
        array $credentials = [],
    ): void {
        IntegrationSetting::updateOrCreate(
            [
                'scope_type' => $scope->value,
                'scope_id' => $scope === IntegrationScope::Account ? $account?->id : 0,
                'provider' => $provider->value,
            ],
            [
                'account_id' => $account?->id,
                'category' => IntegrationCategory::Fiscalization->value,
                'is_enabled' => true,
                'credentials' => $credentials,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function checkboxCredentials(): array
    {
        return [
            'license_key' => 'license-key',
            'cashier_login' => 'cashier-login',
            'cashier_password' => 'cashier-password',
        ];
    }
}
