<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\NormalizeCustomerClassPasses;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduledClassCancellationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_save_class_pass_cancellation_rules(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'pass_rules']))
            ->assertOk()
            ->assertSee(__('app.class_passes_and_classes'))
            ->assertSee(__('app.return_cancelled_class_sessions'))
            ->assertSee(__('app.return_cancelled_class_sessions_help'))
            ->assertSee(__('app.bonus_sessions_count'))
            ->assertSee(__('app.extend_cancelled_class_pass_days'))
            ->assertSee(__('app.extend_cancelled_class_pass_days_help'))
            ->assertSee(__('app.extension_days_count'))
            ->assertSee(__('app.schedule_generation_policy'))
            ->assertDontSee('Return X classes')
            ->assertDontSee('Повернути X занять')
            ->assertSee('class_pass_cancellation_rules_present', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                'brand_tab' => 'pass_rules',
                'name' => $account->name,
                'slug' => $account->slug,
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'UTC',
                'class_pass_cancellation_rules_present' => '1',
                'class_pass_cancellation_rules' => [
                    'return_sessions_enabled' => '1',
                    'return_sessions_count' => '2',
                    'extend_days_enabled' => '1',
                    'extend_days_count' => '5',
                ],
                'schedule_generation_weeks' => '4',
            ])
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'pass_rules']));

        $this->assertSame([
            'return_sessions_enabled' => true,
            'return_sessions_count' => 2,
            'extend_days_enabled' => true,
            'extend_days_count' => 5,
        ], $account->fresh()->classPassCancellationRules());
        $this->assertSame(4, $account->fresh()->scheduleGenerationWeeks());
    }

    public function test_owner_can_cancel_and_restore_class_with_pass_compensation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00', 'UTC'));

        $context = $this->context([
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => true,
                'return_sessions_count' => 2,
                'extend_days_enabled' => true,
                'extend_days_count' => 5,
            ],
        ]);
        $customerClassPass = $this->issuePass($context, sessions: 1);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $booking = $this->bookCustomer($context, $scheduledClass);
        $reservation = $booking->classPassReservation()->firstOrFail();

        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);

        $cancelResponse = $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $scheduledClass]));

        $cancelResponse
            ->assertOk()
            ->assertJsonPath('message', __('app.scheduled_class_cancelled'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);
        $this->assertStringContainsString(__('app.scheduled_class_cancelled_by_studio'), $cancelResponse->json('card_html'));

        $scheduledClass->refresh();
        $booking->refresh();
        $reservation->refresh();
        $customerClassPass->refresh();

        $this->assertSame(ScheduledClassStatus::Cancelled, $scheduledClass->status);
        $this->assertTrue($scheduledClass->is_manually_modified);
        $this->assertSame(ClassBookingStatus::Cancelled, $booking->status);
        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation->status);
        $this->assertSame(2, $customerClassPass->sessions_count);
        $this->assertSame(35, $customerClassPass->validity_days);
        $this->assertSame(185, $customerClassPass->total_validity_days);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->used_sessions_count);

        $cancellation = ScheduledClassCancellation::whereBelongsTo($scheduledClass, 'scheduledClass')->firstOrFail();
        $effect = $cancellation->effects()->firstOrFail();
        $this->assertSame(1, $effect->added_sessions_count);
        $this->assertSame(5, $effect->added_validity_days);

        $restoreResponse = $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.restore', [$context['account'], $scheduledClass]));

        $restoreResponse
            ->assertOk()
            ->assertJsonPath('message', __('app.scheduled_class_restored'));
        $this->assertStringNotContainsString(__('app.scheduled_class_cancelled_by_studio'), $restoreResponse->json('card_html'));

        $scheduledClass->refresh();
        $booking->refresh();
        $reservation->refresh();
        $customerClassPass->refresh();

        $this->assertSame(ScheduledClassStatus::Scheduled, $scheduledClass->status);
        $this->assertSame(ClassBookingStatus::Booked, $booking->status);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);
        $this->assertSame(1, $customerClassPass->sessions_count);
        $this->assertSame(30, $customerClassPass->validity_days);
        $this->assertSame(180, $customerClassPass->total_validity_days);
        $this->assertSame(1, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->used_sessions_count);
        $this->assertNotNull($cancellation->fresh()->restored_at);
        $this->assertNotNull($effect->fresh()->reversed_at);

        Carbon::setTestNow();
    }

    public function test_return_one_session_releases_cancelled_class_without_extra_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00', 'UTC'));

        $context = $this->context([
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => true,
                'return_sessions_count' => 1,
                'extend_days_enabled' => false,
                'extend_days_count' => 1,
            ],
        ]);
        $customerClassPass = $this->issuePass($context, sessions: 1);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $booking = $this->bookCustomer($context, $scheduledClass);

        $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $scheduledClass]))
            ->assertOk();

        $reservation = $booking->classPassReservation()->firstOrFail();
        $customerClassPass->refresh();

        $this->assertSame(CustomerClassPassReservationStatus::Released, $reservation->status);
        $this->assertSame(1, $customerClassPass->sessions_count);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->used_sessions_count);
        $this->assertSame(1, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_cancel_without_return_rule_consumes_reserved_pass_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00', 'UTC'));

        $context = $this->context([
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => false,
                'return_sessions_count' => 1,
                'extend_days_enabled' => false,
                'extend_days_count' => 1,
            ],
        ]);
        $customerClassPass = $this->issuePass($context, sessions: 1);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $booking = $this->bookCustomer($context, $scheduledClass);

        $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $scheduledClass]))
            ->assertOk();

        $reservation = $booking->classPassReservation()->firstOrFail();
        $customerClassPass->refresh();

        $this->assertSame(CustomerClassPassReservationStatus::Used, $reservation->status);
        $this->assertTrue($reservation->used_at->equalTo(Carbon::parse('2026-06-21 10:00:00', 'UTC')));
        $this->assertSame(1, $customerClassPass->sessions_count);
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertSame(0, $customerClassPass->remainingSessionsCount());

        Carbon::setTestNow();
    }

    public function test_restore_is_blocked_after_extra_compensation_session_is_used(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00', 'UTC'));

        $context = $this->context([
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => true,
                'return_sessions_count' => 2,
                'extend_days_enabled' => false,
                'extend_days_count' => 1,
            ],
        ]);
        $customerClassPass = $this->issuePass($context, sessions: 1);
        $cancelledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $this->bookCustomer($context, $cancelledClass);

        $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $cancelledClass]))
            ->assertOk();

        $replacementClass = $this->scheduledClass($context, '2026-06-22 10:00:00');
        $replacementBooking = $this->bookCustomer($context, $replacementClass);
        $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.bookings.update', [$context['account'], $replacementBooking]), [
                'status' => 'attended',
            ])
            ->assertOk();

        app(NormalizeCustomerClassPasses::class)->forPass($customerClassPass->refresh());

        $restoreResponse = $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.restore', [$context['account'], $cancelledClass]));

        $restoreResponse
            ->assertUnprocessable()
            ->assertJsonPath('errors.scheduled_class.0', __('app.scheduled_class_restore_compensation_used'));

        $this->assertSame(ScheduledClassStatus::Cancelled, $cancelledClass->fresh()->status);
        $this->assertSame(ClassBookingStatus::Cancelled, $cancelledClass->classBookings()->firstOrFail()->status);
        $this->assertSame(2, $customerClassPass->fresh()->sessions_count);
        $this->assertSame(1, $customerClassPass->fresh()->used_sessions_count);

        Carbon::setTestNow();
    }

    public function test_cancel_affects_only_booked_active_bookings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00', 'UTC'));

        $context = $this->context([
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => true,
                'return_sessions_count' => 1,
                'extend_days_enabled' => false,
                'extend_days_count' => 1,
            ],
        ]);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $bookedCustomer = $context['customer'];
        $attendedCustomer = Customer::factory()->for($context['account'])->create();
        $noShowCustomer = Customer::factory()->for($context['account'])->create();
        $cancelledCustomer = Customer::factory()->for($context['account'])->create();

        $this->issuePass($context, sessions: 1);
        $bookedBooking = $this->bookCustomer($context, $scheduledClass, $bookedCustomer);
        $attendedBooking = $this->booking($context, $scheduledClass, $attendedCustomer, ClassBookingStatus::Attended);
        $noShowBooking = $this->booking($context, $scheduledClass, $noShowCustomer, ClassBookingStatus::NoShow);
        $alreadyCancelledBooking = $this->booking($context, $scheduledClass, $cancelledCustomer, ClassBookingStatus::Cancelled);

        $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $scheduledClass]))
            ->assertOk();

        $this->assertSame(ClassBookingStatus::Cancelled, $bookedBooking->fresh()->status);
        $this->assertSame(ClassBookingStatus::Attended, $attendedBooking->fresh()->status);
        $this->assertSame(ClassBookingStatus::NoShow, $noShowBooking->fresh()->status);
        $this->assertSame(ClassBookingStatus::Cancelled, $alreadyCancelledBooking->fresh()->status);
        $this->assertSame(1, ScheduledClassCancellation::whereBelongsTo($scheduledClass, 'scheduledClass')->firstOrFail()->effects()->count());

        Carbon::setTestNow();
    }

    public function test_owner_can_cancel_class_until_one_hour_after_finish(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-21 09:00:00', 'UTC'));

        $context = $this->context([
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => true,
                'return_sessions_count' => 1,
                'extend_days_enabled' => false,
                'extend_days_count' => 1,
            ],
        ]);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $this->bookCustomer($context, $scheduledClass);

        Carbon::setTestNow(Carbon::parse('2026-06-21 12:00:00', 'UTC'));

        $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $scheduledClass]))
            ->assertOk();

        $this->assertSame(ScheduledClassStatus::Cancelled, $scheduledClass->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_owner_cannot_cancel_class_more_than_one_hour_after_finish(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-21 09:00:00', 'UTC'));

        $context = $this->context([
            'class_pass_cancellation_rules' => [
                'return_sessions_enabled' => true,
                'return_sessions_count' => 2,
                'extend_days_enabled' => true,
                'extend_days_count' => 5,
            ],
        ]);
        $customerClassPass = $this->issuePass($context, sessions: 1);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $booking = $this->bookCustomer($context, $scheduledClass);
        $reservation = $booking->classPassReservation()->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-06-21 12:01:00', 'UTC'));

        $this->actingAs($context['owner'])
            ->patchJson(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $scheduledClass]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.scheduled_class.0', __('app.scheduled_class_cancel_unavailable'));

        $this->assertSame(ScheduledClassStatus::Scheduled, $scheduledClass->fresh()->status);
        $this->assertSame(ClassBookingStatus::Booked, $booking->fresh()->status);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->fresh()->status);
        $this->assertSame(1, $customerClassPass->fresh()->sessions_count);
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, ScheduledClassCancellation::whereBelongsTo($scheduledClass, 'scheduledClass')->count());

        Carbon::setTestNow();
    }

    public function test_cancel_button_is_hidden_after_studio_cancellation_window_closes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-21 12:01:00', 'UTC'));

        $context = $this->context();
        $expiredClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $openClass = $this->scheduledClass($context, '2026-06-21 10:30:00');

        $response = $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.scheduled-classes.index', $context['account']));

        $response
            ->assertOk()
            ->assertDontSee(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $expiredClass]), false)
            ->assertSee(route('dashboard.accounts.scheduled-classes.cancel', [$context['account'], $openClass]), false);

        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $accountAttributes
     * @return array<string, mixed>
     */
    private function context(array $accountAttributes = []): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create($accountAttributes + ['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $customer = Customer::factory()->for($account)->create(['name' => 'Олена Коваль']);

        return compact('owner', 'account', 'location', 'room', 'classType', 'trainerType', 'trainer', 'customer');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function issuePass(array $context, int $sessions): CustomerClassPass
    {
        $plan = ClassPassPlan::factory()->for($context['account'])->create([
            'sessions_count' => $sessions,
            'validity_days' => 30,
            'total_validity_days' => 180,
        ]);
        $plan->classTypes()->sync([$context['classType']->id]);
        $plan->trainerTypes()->sync([$context['trainerType']->id]);

        return app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scheduledClass(array $context, string $startsAt): ScheduledClass
    {
        $startsAt = Carbon::parse($startsAt, 'UTC');

        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['classType'])
            ->for($context['trainer'])
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function bookCustomer(array $context, ScheduledClass $scheduledClass, ?Customer $customer = null): ClassBooking
    {
        $customer ??= $context['customer'];

        $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $customer->id,
            ])
            ->assertCreated();

        return $scheduledClass->classBookings()->whereBelongsTo($customer)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function booking(array $context, ScheduledClass $scheduledClass, Customer $customer, ClassBookingStatus $status): ClassBooking
    {
        return ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'status' => $status->value,
                'attended_at' => $status === ClassBookingStatus::Attended ? $scheduledClass->starts_at : null,
            ]);
    }
}
