<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\NormalizeCustomerClassPasses;
use App\Enums\CustomerClassPassReservationStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPassReservation;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerClassPassBusinessFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bookings_reserve_available_sessions_and_show_alert_when_overbooked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $firstClass = $this->scheduledClass($context, '2026-06-21 10:00:00');
        $secondClass = $this->scheduledClass($context, '2026-06-22 10:00:00');

        $firstResponse = $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $firstClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $this->assertStringContainsString($customerClassPass->code, $firstResponse->json('card_html'));

        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);

        $secondResponse = $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $secondClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        $this->assertStringContainsString(__('app.no_matching_class_pass_alert'), $secondResponse->json('card_html'));

        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);
        $this->assertSame(1, CustomerClassPassReservation::where('customer_class_pass_id', $customerClassPass->id)->count());

        Carbon::setTestNow();
    }

    public function test_attendance_uses_reserved_session_and_reverting_to_booked_restores_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 10:00:00'));
        $context = $this->context();
        $plan = $this->plan($context, sessions: 2);
        $customerClassPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan);
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');

        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
            'customer_id' => $context['customer']->id,
        ]);

        $booking = $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();

        $this->actingAs($context['owner'])
            ->patch(route('dashboard.accounts.bookings.update', [$context['account'], $booking]), ['status' => 'attended'])
            ->assertRedirect();

        $customerClassPass->refresh();
        $this->assertSame(0, $customerClassPass->reserved_sessions_count);
        $this->assertSame(1, $customerClassPass->used_sessions_count);
        $this->assertNotNull($customerClassPass->opened_at);
        $this->assertNotNull($customerClassPass->expires_at);

        $this->actingAs($context['owner'])
            ->patch(route('dashboard.accounts.bookings.update', [$context['account'], $booking]), ['status' => 'booked'])
            ->assertRedirect();

        $customerClassPass->refresh();
        $reservation = $booking->classPassReservation()->firstOrFail();
        $this->assertSame(1, $customerClassPass->reserved_sessions_count);
        $this->assertSame(0, $customerClassPass->used_sessions_count);
        $this->assertNull($customerClassPass->opened_at);
        $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);

        Carbon::setTestNow();
    }

    public function test_oldest_matching_pass_is_reserved_first(): void
    {
        $context = $this->context();
        $plan = $this->plan($context, sessions: 1);
        $oldPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-01'));
        $newPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $plan, purchasedAt: Carbon::parse('2026-06-10'));
        $scheduledClass = $this->scheduledClass($context, '2026-06-21 10:00:00');

        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
            'customer_id' => $context['customer']->id,
        ]);

        $this->assertSame(1, $oldPass->fresh()->reserved_sessions_count);
        $this->assertSame(0, $newPass->fresh()->reserved_sessions_count);
    }

    public function test_private_lessons_and_rentals_match_trainer_type_and_room(): void
    {
        $context = $this->context();
        $privateType = ClassType::factory()->for($context['account'])->create(['schedule_kind' => 'private_lesson']);
        $rentalType = ClassType::factory()->for($context['account'])->create(['schedule_kind' => 'room_rental']);
        $topTrainerType = TrainerType::factory()->for($context['account'])->create(['name' => 'Top']);
        $topTrainer = Trainer::factory()->for($context['account'])->for($topTrainerType)->create();
        $otherRoom = Room::factory()->for($context['account'])->for($context['location'])->create(['name' => 'Other room']);
        $privatePlan = $this->plan($context, sessions: 1, classType: $privateType, trainerType: $topTrainerType);
        $rentalPlan = $this->plan($context, sessions: 1, classType: $rentalType, trainerType: null, room: $otherRoom);
        $privatePass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $privatePlan);
        $rentalPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $rentalPlan);
        $privateClass = $this->scheduledClass($context, '2026-06-21 10:00:00', $privateType, $topTrainer);
        $rentalClassWrongRoom = $this->scheduledClass($context, '2026-06-22 10:00:00', $rentalType, null, $context['room']);
        $rentalClassRightRoom = $this->scheduledClass($context, '2026-06-23 10:00:00', $rentalType, null, $otherRoom);

        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $privateClass]), [
            'customer_id' => $context['customer']->id,
        ]);
        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $rentalClassWrongRoom]), [
            'customer_id' => $context['customer']->id,
        ]);
        $this->actingAs($context['owner'])->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $rentalClassRightRoom]), [
            'customer_id' => $context['customer']->id,
        ]);

        $this->assertSame(1, $privatePass->fresh()->reserved_sessions_count);
        $this->assertSame(1, $rentalPass->fresh()->reserved_sessions_count);
        $this->assertNull($rentalClassWrongRoom->classBookings()->firstOrFail()->classPassReservation);
    }

    public function test_normalizer_closes_expired_and_used_up_passes(): void
    {
        $context = $this->context();
        $expiredPlan = $this->plan($context, sessions: 4);
        $usedUpPlan = $this->plan($context, sessions: 1);
        $expiredPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $expiredPlan);
        $usedUpPass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $usedUpPlan);

        $expiredBooking = $this->bookingWithUsedReservation($context, $expiredPass, '2026-05-01 10:00:00');
        $usedUpBooking = $this->bookingWithUsedReservation($context, $usedUpPass, '2026-06-19 10:00:00');

        app(NormalizeCustomerClassPasses::class)->execute();

        $this->assertSame('expired', $expiredPass->fresh()->status->value);
        $this->assertFalse($expiredPass->fresh()->is_active);
        $this->assertSame('used_up', $usedUpPass->fresh()->status->value);
        $this->assertFalse($usedUpPass->fresh()->is_active);
        $this->assertNotNull($expiredBooking->fresh());
        $this->assertNotNull($usedUpBooking->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
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
    private function plan(array $context, int $sessions, ?ClassType $classType = null, ?TrainerType $trainerType = null, ?Room $room = null): ClassPassPlan
    {
        $plan = ClassPassPlan::factory()->for($context['account'])->create(['sessions_count' => $sessions]);
        $plan->classTypes()->sync([($classType ?? $context['classType'])->id]);
        $plan->trainerTypes()->sync($trainerType === null ? ($classType?->schedule_kind?->value === 'room_rental' ? [] : [$context['trainerType']->id]) : [$trainerType->id]);
        $plan->rooms()->sync($room ? [$room->id] : []);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scheduledClass(array $context, string $startsAt, ?ClassType $classType = null, ?Trainer $trainer = null, ?Room $room = null): ScheduledClass
    {
        $startsAt = Carbon::parse($startsAt);

        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($room ?? $context['room'])
            ->for($classType ?? $context['classType'])
            ->for($trainer ?? $context['trainer'])
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function bookingWithUsedReservation(array $context, mixed $customerClassPass, string $usedAt): ClassBooking
    {
        $scheduledClass = $this->scheduledClass($context, $usedAt);
        $booking = $scheduledClass->classBookings()->create([
            'account_id' => $context['account']->id,
            'customer_id' => $context['customer']->id,
            'status' => 'attended',
            'attended_at' => Carbon::parse($usedAt),
        ]);

        $customerClassPass->reservations()->create([
            'account_id' => $context['account']->id,
            'class_booking_id' => $booking->id,
            'scheduled_class_id' => $scheduledClass->id,
            'status' => 'used',
            'reserved_at' => Carbon::parse($usedAt)->subDay(),
            'used_at' => Carbon::parse($usedAt),
        ]);

        return $booking;
    }
}
