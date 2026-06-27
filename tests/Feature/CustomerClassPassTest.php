<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Enums\AccountRole;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerClassPassTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_issue_manual_customer_class_pass(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
                'issued_location_id' => $location->id,
            ])
            ->assertRedirect(route('dashboard.accounts.customers.edit', [$account, $customer]));

        $customerClassPass = $customer->customerClassPasses()->firstOrFail();

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $customerClassPass->code);
        $this->assertSame($plan->price_cents, $customerClassPass->price_cents);
        $this->assertSame($plan->sessions_count, $customerClassPass->sessions_count);
        $this->assertSame($plan->validity_days, $customerClassPass->validity_days);
        $this->assertSame($plan->total_validity_days, $customerClassPass->total_validity_days);
        $this->assertSame($owner->id, $customerClassPass->issued_by_actor_user_id);
        $this->assertSame($owner->name, $customerClassPass->issued_by_actor_name);
        $this->assertSame('owner', $customerClassPass->issued_by_actor_role);
        $this->assertSame($location->id, $customerClassPass->issued_location_id);
        $this->assertFalse($customerClassPass->is_paid);
        $this->assertSame(0, CustomerPurchase::whereBelongsTo($customerClassPass)->count());
        $this->assertTrue($customerClassPass->purchased_at->equalTo(Carbon::parse('2026-06-20 10:00:00')));
        $this->assertTrue($customerClassPass->usable_until_at->equalTo(Carbon::parse('2026-10-18 10:00:00')));
        $this->assertNull($customerClassPass->opened_at);

        Carbon::setTestNow();
    }

    public function test_manual_class_pass_issue_requires_location(): void
    {
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
            ])
            ->assertSessionHasErrors('issued_location_id');
    }

    public function test_paid_manual_class_pass_creates_studio_cash_purchase_with_location(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
                'issued_location_id' => $location->id,
                'is_paid' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.customers.edit', [$account, $customer]));

        $customerClassPass = $customer->customerClassPasses()->firstOrFail();
        $purchase = CustomerPurchase::whereBelongsTo($customerClassPass)->firstOrFail();

        $this->assertTrue($customerClassPass->is_paid);
        $this->assertSame($location->id, $customerClassPass->issued_location_id);
        $this->assertSame(CustomerPurchase::ProviderStudioCash, $purchase->provider);
        $this->assertSame(CustomerPurchase::SourceManualCashClassPass, $purchase->payment_source);
        $this->assertSame($location->id, $purchase->location_id);
        $this->assertSame($customerClassPass->id, $purchase->customer_class_pass_id);
        $this->assertSame('payment_paid', $purchase->status->value);
        $this->assertSame($plan->price_cents, $purchase->amount_cents);
        $this->assertTrue($purchase->paid_at->equalTo(Carbon::parse('2026-06-20 10:00:00')));

        Carbon::setTestNow();
    }

    public function test_manual_class_pass_payment_toggle_creates_and_removes_only_manual_cash_purchase(): void
    {
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan, issuedLocation: $location);

        $this->actingAs($owner)
            ->put(
                route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]),
                $this->classPassUpdatePayload($customerClassPass, $location, isPaid: true),
            )
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.index', $account));

        $customerClassPass->refresh();
        $manualPurchase = CustomerPurchase::whereBelongsTo($customerClassPass)
            ->where('payment_source', CustomerPurchase::SourceManualCashClassPass)
            ->firstOrFail();

        $this->assertTrue($customerClassPass->is_paid);
        $this->assertSame(CustomerPurchase::ProviderStudioCash, $manualPurchase->provider);
        $this->assertSame($location->id, $manualPurchase->location_id);

        $this->actingAs($owner)
            ->put(
                route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]),
                $this->classPassUpdatePayload($customerClassPass, $location, isPaid: false),
            )
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.index', $account));

        $this->assertFalse($customerClassPass->fresh()->is_paid);
        $this->assertSame(0, CustomerPurchase::whereKey($manualPurchase->id)->count());
    }

    public function test_online_purchase_is_not_deleted_when_paid_flag_is_removed_from_pass(): void
    {
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'source' => 'online_payment',
                'issued_location_id' => $location->id,
                'is_paid' => true,
            ]);
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->for($customerClassPass, 'customerClassPass')
            ->create([
                'location_id' => $location->id,
                'payment_source' => CustomerPurchase::SourceOnlineCheckout,
                'status' => 'payment_paid',
            ]);

        $this->actingAs($owner)
            ->put(
                route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]),
                $this->classPassUpdatePayload($customerClassPass, $location, isPaid: false),
            )
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.index', $account));

        $this->assertFalse($customerClassPass->fresh()->is_paid);
        $this->assertSame(1, CustomerPurchase::whereKey($purchase->id)->count());
    }

    public function test_trial_class_pass_is_blocked_after_multiple_bookings(): void
    {
        [$owner, $account, $customer, $plan, $scheduledClass, $location] = $this->passContext(isTrial: true);

        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create();
        ClassBooking::factory()
            ->for($account)
            ->for($this->matchingScheduledClass($scheduledClass, '2026-06-21 10:00:00'))
            ->for($customer)
            ->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
                'issued_location_id' => $location->id,
            ])
            ->assertSessionHasErrors('class_pass_plan_id');
    }

    public function test_manual_trial_class_pass_allows_single_booking_without_reservation(): void
    {
        [$owner, $account, $customer, $plan, $scheduledClass, $location] = $this->passContext(isTrial: true);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
                'issued_location_id' => $location->id,
            ])
            ->assertRedirect(route('dashboard.accounts.customers.edit', [$account, $customer]));

        $customerClassPass = $customer->customerClassPasses()->firstOrFail();

        $this->assertSame($customerClassPass->id, $booking->classPassReservation()->firstOrFail()->customer_class_pass_id);
    }

    public function test_customer_list_searches_by_class_pass_code(): void
    {
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();
        app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $code = $customer->customerClassPasses()->firstOrFail()->code;

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.index', ['account' => $account, 'q' => $code]))
            ->assertOk()
            ->assertSee($customer->name);
    }

    public function test_customer_visit_history_shows_translated_class_pass_label(): void
    {
        app()->setLocale('uk');
        [$owner, $account, $customer, $plan, $scheduledClass, $location] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan, issuedLocation: $location);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create();

        $customerClassPass->reservations()->create([
            'account_id' => $account->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => CustomerClassPassReservationStatus::Reserved->value,
            'reserved_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.edit', [$account, $customer]))
            ->assertOk()
            ->assertSee(__('app.class_pass').': '.$customerClassPass->code)
            ->assertDontSee('app.class_pass');
    }

    public function test_customer_class_pass_index_filters_by_payment_status_and_links_unpaid_notice(): void
    {
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();
        $paidPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan, issuedLocation: $location, isPaid: true);
        $unpaidPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan, issuedLocation: $location);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.index', $account))
            ->assertOk()
            ->assertSee(__('app.unpaid_class_passes_notice', ['count' => 1]))
            ->assertSee(__('app.show_unpaid_class_passes'))
            ->assertSee($paidPass->code)
            ->assertSee($unpaidPass->code);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.index', [
                'account' => $account,
                'payment_status' => 'unpaid',
            ]))
            ->assertOk()
            ->assertSee($unpaidPass->code)
            ->assertSee(__('app.class_pass_unpaid'))
            ->assertDontSee($paidPass->code);
    }

    public function test_manual_class_pass_issue_form_requires_confirmation(): void
    {
        [$owner, $account, $customer] = $this->passContext();

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.edit', [$account, $customer]))
            ->assertOk()
            ->assertSee(__('app.confirm_issue_class_pass_title'))
            ->assertSee(__('app.confirm_issue_class_pass_body'));

        $html = $response->getContent();

        $this->assertStringContainsString('data-confirm-action', $html);
        $this->assertStringContainsString('data-confirm-icon="ticket"', $html);
        $this->assertStringContainsString('data-confirm-variant="success"', $html);
        $this->assertStringContainsString('data-confirm-accept="'.__('app.issue_class_pass').'"', $html);
    }

    public function test_owner_can_preview_and_apply_existing_class_pass_backfill(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:30:00'));
        [$owner, $account, $customer, $plan, $scheduledClass] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $pastClass = $this->matchingScheduledClass($scheduledClass, '2026-06-20 10:00:00');
        $futureClass = $this->matchingScheduledClass($scheduledClass, '2026-06-21 10:00:00');
        $pastBooking = ClassBooking::factory()
            ->for($account)
            ->for($pastClass)
            ->for($customer)
            ->create();
        $futureBooking = ClassBooking::factory()
            ->for($account)
            ->for($futureClass)
            ->for($customer)
            ->create();

        $this->assertFalse($pastBooking->classPassReservation()->exists());
        $this->assertFalse($futureBooking->classPassReservation()->exists());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.edit', [$account, $customer, 'class_pass_backfill_preview' => 1]))
            ->assertOk()
            ->assertSee(__('app.preview_class_pass_backfill'))
            ->assertSee(__('app.class_pass_backfill_title'))
            ->assertSee($customerClassPass->code)
            ->assertSee(__('app.used').': 1')
            ->assertSee(__('app.reserved').': 1');

        $this->assertFalse($pastBooking->classPassReservation()->exists());
        $this->assertFalse($futureBooking->classPassReservation()->exists());
        $this->assertSame(0, $customerClassPass->fresh()->used_sessions_count);
        $this->assertSame(0, $customerClassPass->fresh()->reserved_sessions_count);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.class-passes.backfill', [$account, $customer]))
            ->assertRedirect(route('dashboard.accounts.customers.edit', [$account, $customer]))
            ->assertSessionHas('status', __('app.customer_class_pass_backfill_applied', [
                'used' => 1,
                'reserved' => 1,
            ]));

        $pastReservation = $pastBooking->classPassReservation()->firstOrFail();
        $futureReservation = $futureBooking->classPassReservation()->firstOrFail();
        $customerClassPass->refresh();

        $this->assertSame(1, $customer->customerClassPasses()->count());
        $this->assertSame('booked', $pastBooking->fresh()->status->value);
        $this->assertSame('booked', $futureBooking->fresh()->status->value);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $pastReservation->status);
        $this->assertTrue($pastReservation->used_at->equalTo(Carbon::parse('2026-06-20 10:00:00')));
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $futureReservation->status);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(1, $customerClassPass->reserved_sessions_count);
        $this->assertSame(2, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_customer_class_pass_adjustment_form_uses_single_input_with_direction_buttons(): void
    {
        [$owner, $account, $customer, $plan] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]))
            ->assertOk()
            ->assertSee(__('app.class_pass_session_adjustment'))
            ->assertSee(__('app.confirm_add_class_pass_sessions_title'))
            ->assertSee(__('app.confirm_remove_class_pass_sessions_title'));

        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'name="sessions_delta"'));
        $this->assertSame(1, substr_count($html, 'name="reason"'));
        $this->assertStringContainsString('name="direction" value="add"', $html);
        $this->assertStringContainsString('name="direction" value="subtract"', $html);
        $this->assertStringContainsString('data-confirm-icon="plus"', $html);
        $this->assertStringContainsString('data-confirm-icon="minus"', $html);
        $this->assertStringContainsString('data-confirm-variant="success"', $html);
        $this->assertStringContainsString('data-confirm-variant="danger"', $html);
    }

    public function test_owner_can_add_sessions_to_customer_class_pass_and_history_is_stored(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'add',
                'sessions_delta' => 2,
                'reason' => 'Medical recovery compensation',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]))
            ->assertSessionHas('status', __('app.customer_class_pass_adjusted'));

        $customerClassPass->refresh();
        $adjustment = $customerClassPass->adjustments()->firstOrFail();

        $this->assertSame(6, $customerClassPass->sessions_count);
        $this->assertSame(2, $adjustment->sessions_delta);
        $this->assertSame(4, $adjustment->previous_sessions_count);
        $this->assertSame(6, $adjustment->new_sessions_count);
        $this->assertSame('Medical recovery compensation', $adjustment->reason);
        $this->assertSame($owner->id, $adjustment->user_id);
        $this->assertSame($account->id, $adjustment->account_id);

        Carbon::setTestNow();
    }

    public function test_owner_can_remove_sessions_from_customer_class_pass_and_history_is_stored(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'subtract',
                'sessions_delta' => 2,
                'reason' => 'Manual correction after wrong issue',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]))
            ->assertSessionHas('status', __('app.customer_class_pass_adjusted'));

        $customerClassPass->refresh();
        $adjustment = $customerClassPass->adjustments()->firstOrFail();

        $this->assertSame(2, $customerClassPass->sessions_count);
        $this->assertSame(-2, $adjustment->sessions_delta);
        $this->assertSame(4, $adjustment->previous_sessions_count);
        $this->assertSame(2, $adjustment->new_sessions_count);
        $this->assertSame('Manual correction after wrong issue', $adjustment->reason);
        $this->assertSame($owner->id, $adjustment->user_id);
        $this->assertSame($account->id, $adjustment->account_id);

        Carbon::setTestNow();
    }

    public function test_customer_class_pass_dates_display_and_save_in_account_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 02:30:00', 'UTC'));
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();
        $account->update(['timezone' => 'America/New_York']);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $customerClassPass->update([
            'purchased_at' => Carbon::parse('2026-06-20 02:30:00', 'UTC'),
            'opened_at' => Carbon::parse('2026-06-20 02:30:00', 'UTC'),
            'expires_at' => Carbon::parse('2026-07-20 02:30:00', 'UTC'),
            'usable_until_at' => Carbon::parse('2026-10-18 02:30:00', 'UTC'),
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'add',
                'sessions_delta' => 1,
                'reason' => 'Timezone audit',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]))
            ->assertOk()
            ->assertSee('value="2026-06-19T22:30"', false)
            ->assertSee('2026-06-19 22:30')
            ->assertDontSee('2026-06-20T02:30')
            ->assertDontSee('2026-06-20 02:30');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]), [
                'status' => CustomerClassPassStatus::Active->value,
                'is_active' => '1',
                'issued_location_id' => $location->id,
                'purchased_at' => '2026-06-19T22:30',
                'opened_at' => '',
                'expires_at' => '',
                'closed_at' => '',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.index', $account));

        $customerClassPass->refresh();

        $this->assertTrue($customerClassPass->purchased_at->equalTo(Carbon::parse('2026-06-20 02:30:00', 'UTC')));
        $this->assertNull($customerClassPass->opened_at);
        $this->assertNull($customerClassPass->expires_at);
        $this->assertNull($customerClassPass->closed_at);

        Carbon::setTestNow();
    }

    public function test_session_removal_cannot_drop_total_below_used_or_reserved_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan, $scheduledClass] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $usedBooking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create(['status' => 'attended', 'attended_at' => Carbon::parse('2026-06-19 10:00:00')]);
        $reservedScheduledClass = ScheduledClass::factory()
            ->for($account)
            ->create([
                'starts_at' => Carbon::parse('2026-06-22 10:00:00'),
                'ends_at' => Carbon::parse('2026-06-22 11:00:00'),
            ]);
        $reservedBooking = ClassBooking::factory()
            ->for($account)
            ->for($reservedScheduledClass)
            ->for($customer)
            ->create();

        $customerClassPass->reservations()->create([
            'account_id' => $account->id,
            'class_booking_id' => $usedBooking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => CustomerClassPassReservationStatus::Used->value,
            'reserved_at' => Carbon::parse('2026-06-18 10:00:00'),
            'used_at' => Carbon::parse('2026-06-19 10:00:00'),
        ]);
        $customerClassPass->reservations()->create([
            'account_id' => $account->id,
            'class_booking_id' => $reservedBooking->id,
            'scheduled_class_id' => $reservedScheduledClass->id,
            'status' => CustomerClassPassReservationStatus::Reserved->value,
            'reserved_at' => Carbon::parse('2026-06-20 09:00:00'),
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'subtract',
                'sessions_delta' => 3,
                'reason' => 'Invalid correction',
            ])
            ->assertSessionHasErrors('sessions_delta');

        $customerClassPass->refresh();
        $this->assertSame(4, $customerClassPass->sessions_count);
        $this->assertSame(0, $customerClassPass->adjustments()->count());

        Carbon::setTestNow();
    }

    public function test_non_owner_cannot_add_sessions_to_customer_class_pass(): void
    {
        [, $account, $customer, $plan] = $this->passContext();
        $trainer = User::factory()->create();
        $account->users()->attach($trainer->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [],
        ]);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $this->actingAs($trainer)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'add',
                'sessions_delta' => 2,
                'reason' => 'Manager attempt',
            ])
            ->assertForbidden();

        $customerClassPass->refresh();
        $this->assertSame(4, $customerClassPass->sessions_count);
        $this->assertSame(0, $customerClassPass->adjustments()->count());
    }

    public function test_trainer_with_issue_permission_can_issue_but_cannot_adjust_customer_class_pass(): void
    {
        [$owner, $account, $customer, $plan, , $location] = $this->passContext();
        $trainerUser = User::factory()->create([
            'name' => 'Trainer Actor',
            'email' => 'trainer-actor@example.com',
        ]);
        $account->users()->attach($trainerUser->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::IssueCustomerClassPasses->value],
        ]);
        $trainerProfile = Trainer::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->for($account->defaultTrainerType(), 'trainerType')
            ->create(['name' => 'Trainer Actor']);

        $this->actingAs($trainerUser)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
                'issued_location_id' => $location->id,
            ])
            ->assertRedirect(route('dashboard.accounts.customers.edit', [$account, $customer]));

        $customerClassPass = $customer->customerClassPasses()->firstOrFail();

        $this->assertSame($trainerUser->id, $customerClassPass->issued_by_actor_user_id);
        $this->assertSame($trainerProfile->id, $customerClassPass->issued_by_actor_trainer_id);
        $this->assertSame('Trainer Actor', $customerClassPass->issued_by_actor_name);
        $this->assertSame('trainer-actor@example.com', $customerClassPass->issued_by_actor_email);
        $this->assertSame('trainer', $customerClassPass->issued_by_actor_role);

        $this->actingAs($trainerUser)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'add',
                'sessions_delta' => 1,
                'reason' => 'Issue-only user attempt',
            ])
            ->assertForbidden();

        $this->assertSame(0, $customerClassPass->adjustments()->count());
    }

    public function test_trainer_with_manage_permission_can_edit_and_adjust_but_cannot_issue_customer_class_pass(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [, $account, $customer, $plan, , $location] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $trainerUser = User::factory()->create([
            'name' => 'Pass Manager',
            'email' => 'pass-manager@example.com',
        ]);
        $account->users()->attach($trainerUser->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageCustomerClassPasses->value],
        ]);
        $trainerProfile = Trainer::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->for($account->defaultTrainerType(), 'trainerType')
            ->create(['name' => 'Pass Manager']);

        $this->actingAs($trainerUser)
            ->post(route('dashboard.accounts.customers.class-passes.store', [$account, $customer]), [
                'class_pass_plan_id' => $plan->id,
            ])
            ->assertForbidden();

        $this->actingAs($trainerUser)
            ->put(route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]), [
                'status' => CustomerClassPassStatus::Active->value,
                'issued_location_id' => $location->id,
                'purchased_at' => '2026-06-20T10:00',
                'opened_at' => null,
                'expires_at' => null,
                'closed_at' => null,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.index', $account));

        $this->actingAs($trainerUser)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'add',
                'sessions_delta' => 1,
                'reason' => 'Trainer compensation',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]));

        $adjustment = $customerClassPass->adjustments()->firstOrFail();

        $this->assertSame($trainerUser->id, $adjustment->actor_user_id);
        $this->assertSame($trainerProfile->id, $adjustment->actor_trainer_id);
        $this->assertSame('Pass Manager', $adjustment->actor_name);
        $this->assertSame('pass-manager@example.com', $adjustment->actor_email);
        $this->assertSame('trainer', $adjustment->actor_role);

        $actorUserId = $trainerUser->id;
        $trainerUser->delete();
        $adjustment->refresh();

        $this->assertSame($actorUserId, $adjustment->actor_user_id);
        $this->assertSame('Pass Manager', $adjustment->actor_name);

        Carbon::setTestNow();
    }

    public function test_owner_can_reopen_valid_used_up_pass_with_session_adjustment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan, $scheduledClass] = $this->passContext();
        $plan->update(['sessions_count' => 1]);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create(['status' => 'attended', 'attended_at' => Carbon::parse('2026-06-19 10:00:00')]);

        $customerClassPass->reservations()->create([
            'account_id' => $account->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => CustomerClassPassReservationStatus::Used->value,
            'reserved_at' => Carbon::parse('2026-06-18 10:00:00'),
            'used_at' => Carbon::parse('2026-06-19 10:00:00'),
        ]);
        $customerClassPass->forceFill([
            'status' => CustomerClassPassStatus::UsedUp->value,
            'is_active' => false,
            'used_sessions_count' => 1,
            'closed_at' => Carbon::parse('2026-06-19 10:00:00'),
        ])->save();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]), [
                'direction' => 'add',
                'sessions_delta' => 1,
                'reason' => 'Force majeure replacement',
            ])
            ->assertRedirect(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]));

        $customerClassPass->refresh();
        $this->assertSame(2, $customerClassPass->sessions_count);
        $this->assertSame(CustomerClassPassStatus::Active, $customerClassPass->status);
        $this->assertTrue($customerClassPass->is_active);
        $this->assertNull($customerClassPass->closed_at);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(1, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_session_adjustments_reject_cancelled_expired_and_cross_account_passes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        [$owner, $account, $customer, $plan] = $this->passContext();
        $cancelledPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);
        $expiredPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan, purchasedAt: Carbon::parse('2026-01-01 10:00:00'));

        $cancelledPass->forceFill([
            'status' => CustomerClassPassStatus::Cancelled->value,
            'is_active' => false,
            'closed_at' => now(),
        ])->save();
        $expiredPass->forceFill([
            'status' => CustomerClassPassStatus::Expired->value,
            'is_active' => false,
            'usable_until_at' => Carbon::parse('2026-01-10 10:00:00'),
            'closed_at' => Carbon::parse('2026-01-10 10:00:00'),
        ])->save();

        $payload = ['direction' => 'add', 'sessions_delta' => 1, 'reason' => 'Invalid compensation'];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $cancelledPass]), $payload)
            ->assertSessionHasErrors('sessions_delta');
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $expiredPass]), $payload)
            ->assertSessionHasErrors('sessions_delta');

        $otherAccount = Account::factory()->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();
        $otherPlan = ClassPassPlan::factory()->for($otherAccount)->create();
        $otherPass = app(IssueCustomerClassPass::class)->execute($otherAccount, $otherCustomer, $otherPlan);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $otherPass]), $payload)
            ->assertNotFound();

        $this->assertSame(0, $cancelledPass->adjustments()->count());
        $this->assertSame(0, $expiredPass->adjustments()->count());
        $this->assertSame(0, $otherPass->adjustments()->count());

        Carbon::setTestNow();
    }

    public function test_purchased_pass_keeps_session_snapshot_when_plan_changes(): void
    {
        [$owner, $account, $customer, $plan] = $this->passContext();
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        $plan->update([
            'sessions_count' => 35,
            'validity_days' => 45,
            'total_validity_days' => 365,
        ]);

        $customerClassPass->refresh();
        $this->assertSame(4, $customerClassPass->sessions_count);
        $this->assertSame(30, $customerClassPass->validity_days);
        $this->assertSame(120, $customerClassPass->total_validity_days);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass]))
            ->assertOk()
            ->assertSee((string) $customerClassPass->sessions_count)
            ->assertSee(__('app.remove_class_pass_sessions'))
            ->assertSee('name="direction" value="subtract"', false);
    }

    /**
     * @return array{0: User, 1: Account, 2: Customer, 3: ClassPassPlan, 4: ScheduledClass, 5: Location}
     */
    private function passContext(bool $isTrial = false): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $customer = Customer::factory()->for($account)->create(['name' => 'Олена Коваль']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'sessions_count' => 4,
            'validity_days' => 30,
            'total_validity_days' => 120,
            'is_trial' => $isTrial,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $plan->trainerTypes()->sync([$trainerType->id]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create();

        return [$owner, $account, $customer, $plan, $scheduledClass, $location];
    }

    /**
     * @return array<string, string>
     */
    private function classPassUpdatePayload(CustomerClassPass $customerClassPass, Location $location, bool $isPaid): array
    {
        $account = $customerClassPass->account()->firstOrFail();
        $timezone = $account->timezone ?? config('app.timezone');
        $formatDate = static fn ($date): string => $date ? $date->copy()->timezone($timezone)->format('Y-m-d\TH:i') : '';

        return [
            'status' => $customerClassPass->status->value,
            'issued_location_id' => (string) $location->id,
            'purchased_at' => $formatDate($customerClassPass->purchased_at ?? now()),
            'opened_at' => $formatDate($customerClassPass->opened_at),
            'expires_at' => $formatDate($customerClassPass->expires_at),
            'closed_at' => $formatDate($customerClassPass->closed_at),
            'is_active' => $customerClassPass->is_active ? '1' : '0',
            'is_paid' => $isPaid ? '1' : '0',
        ];
    }

    private function matchingScheduledClass(ScheduledClass $template, string $startsAt): ScheduledClass
    {
        $startsAt = Carbon::parse($startsAt);

        return ScheduledClass::factory()
            ->for($template->account()->firstOrFail())
            ->for($template->location()->firstOrFail())
            ->for($template->room()->firstOrFail())
            ->for($template->classType()->firstOrFail())
            ->for($template->trainer()->firstOrFail())
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
    }
}
