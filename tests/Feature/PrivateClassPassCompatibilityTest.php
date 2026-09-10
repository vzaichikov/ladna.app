<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\NormalizeCustomerClassPasses;
use App\Enums\CustomerClassPassReservationStatus;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PrivateClassPassCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_widening_a_private_plan_preserves_issued_pass_and_ledger_then_covers_each_selected_type(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00', 'UTC'));
        $context = $this->context();
        $pass = app(IssueCustomerClassPass::class)->execute(
            $context['account'],
            $context['customer'],
            $context['plan'],
            issuedBy: $context['owner'],
            issuedLocation: $context['location'],
            isPaid: true,
        );
        $usedClass = $this->scheduledClass($context, $context['genericType'], '2026-09-10 09:00:00');
        $usedBooking = $this->book($context, $usedClass);
        $this->actingAs($context['owner'])
            ->patch(route('dashboard.accounts.bookings.update', [$context['account'], $usedBooking]), ['status' => 'attended'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $reservedBooking = $this->book($context, $this->scheduledClass($context, $context['genericType'], '2026-09-14 10:00:00'));
        $uncoveredClass = $this->scheduledClass($context, $context['jazzType'], '2026-09-15 10:00:00');
        $uncoveredBooking = $this->book($context, $uncoveredClass);

        $this->assertFalse($uncoveredBooking->classPassReservation()->exists());
        $this->assertSame(1, $pass->fresh()->used_sessions_count);
        $this->assertSame(1, $pass->fresh()->reserved_sessions_count);
        $this->assertTrue($pass->fresh()->is_paid);
        $passBefore = $pass->fresh()->getRawOriginal();
        $reservationsBefore = $pass->reservations()->orderBy('id')->get()->toArray();
        $purchasesBefore = $pass->purchases()->orderBy('id')->get()->toArray();
        $bookingsBefore = $context['customer']->classBookings()->orderBy('id')->get()->toArray();

        $this->widenPlan($context);

        $this->assertSame($passBefore, $pass->fresh()->getRawOriginal());
        $this->assertSame($reservationsBefore, $pass->reservations()->orderBy('id')->get()->toArray());
        $this->assertSame($purchasesBefore, $pass->purchases()->orderBy('id')->get()->toArray());
        $this->assertSame($bookingsBefore, $context['customer']->classBookings()->orderBy('id')->get()->toArray());
        $this->assertFalse($uncoveredBooking->classPassReservation()->exists());

        $heelsBooking = $this->book($context, $this->scheduledClass($context, $context['heelsType'], '2026-09-16 10:00:00'));
        $jazzBooking = $this->book($context, $uncoveredClass);

        foreach ([$reservedBooking, $heelsBooking, $jazzBooking] as $booking) {
            $reservation = $booking->classPassReservation()->firstOrFail();
            $this->assertSame($pass->id, $reservation->customer_class_pass_id);
            $this->assertSame(CustomerClassPassReservationStatus::Reserved, $reservation->status);
        }

        $this->assertSame($uncoveredBooking->id, $jazzBooking->id);
        $this->assertSame(CustomerClassPassReservationStatus::Used, $usedBooking->classPassReservation()->firstOrFail()->status);
        $this->assertSame(1, $pass->fresh()->used_sessions_count);
        $this->assertSame(3, $pass->fresh()->reserved_sessions_count);
        $this->assertSame(1, $pass->fresh()->remainingSessionsCount());

        $this->travelTo(Carbon::parse('2026-09-17 10:00:00', 'UTC'));
        $pass = app(NormalizeCustomerClassPasses::class)->forPass($pass->fresh());

        foreach ([$heelsBooking, $jazzBooking] as $booking) {
            $this->assertSame(CustomerClassPassReservationStatus::Used, $booking->classPassReservation()->firstOrFail()->status);
        }

        $this->assertSame(4, $pass->used_sessions_count);
        $this->assertSame(0, $pass->reserved_sessions_count);
        $this->assertSame(1, $pass->remainingSessionsCount());
    }

    public function test_private_pass_with_multiple_types_keeps_unselected_type_trainer_and_room_restrictions(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00', 'UTC'));
        $context = $this->context();
        $this->widenPlan($context);
        $pass = app(IssueCustomerClassPass::class)->execute($context['account'], $context['customer'], $context['plan']->fresh());
        $unselectedType = ClassType::factory()->for($context['account'])->create([
            'name' => 'Private stretching',
            'schedule_kind' => 'private_lesson',
            'activity_direction_id' => null,
        ]);
        $otherTrainerType = TrainerType::factory()->for($context['account'])->create();
        $otherTrainer = Trainer::factory()->for($context['account'])->for($otherTrainerType)->create();
        $otherRoom = Room::factory()->for($context['account'])->for($context['location'])->create();

        $classes = [
            $this->scheduledClass($context, $unselectedType, '2026-09-11 10:00:00'),
            $this->scheduledClass($context, $context['heelsType'], '2026-09-12 10:00:00', trainer: $otherTrainer),
            $this->scheduledClass($context, $context['jazzType'], '2026-09-13 10:00:00', room: $otherRoom),
        ];

        foreach ($classes as $scheduledClass) {
            $booking = $this->book($context, $scheduledClass);
            $this->assertFalse($booking->classPassReservation()->exists());
        }

        $this->assertSame(0, $pass->reservations()->count());
        $this->assertSame(0, $pass->fresh()->reserved_sessions_count);
        $this->assertSame(5, $pass->fresh()->remainingSessionsCount());
    }

    public function test_public_price_and_api_show_one_private_package_with_all_selected_types(): void
    {
        $context = $this->context();
        $this->widenPlan($context);
        $account = $context['account'];
        $location = $context['location'];
        $plan = $context['plan'];
        $checkoutUrl = route('public.class-pass-plans.checkout', [$account->slug, $location->slug, $plan->slug]);

        $response = $this->get(route('public.price', [$account->slug, $location->slug]))
            ->assertOk()
            ->assertSee($plan->name)
            ->assertSee($context['genericType']->name)
            ->assertSee($context['heelsType']->name)
            ->assertSee($context['jazzType']->name);

        $this->assertSame(1, substr_count($response->getContent(), 'href="'.$checkoutUrl.'"'));

        $apiResponse = $this->getJson("/api/v1/public/{$account->slug}/{$location->slug}/price")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'private_lesson')
            ->assertJsonCount(1, 'data.0.sections.0.plans')
            ->assertJsonPath('data.0.sections.0.plans.0.id', $plan->id)
            ->assertJsonPath('data.0.sections.0.plans.0.checkout_url', $checkoutUrl)
            ->assertJsonCount(3, 'data.0.sections.0.plans.0.class_types');

        $this->assertEqualsCanonicalizing(
            [$context['genericType']->id, $context['heelsType']->id, $context['jazzType']->id],
            array_column($apiResponse->json('data.0.sections.0.plans.0.class_types'), 'id'),
        );
    }

    /**
     * @return array{owner: User, account: Account, customer: Customer, location: Location, room: Room, trainerType: TrainerType, trainer: Trainer, genericType: ClassType, heelsType: ClassType, jazzType: ClassType, plan: ClassPassPlan}
     */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC', 'default_language' => 'en', 'default_currency' => 'UAH']);
        $account->addOwner($owner);
        $customer = Customer::factory()->for($account)->create();
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $trainer = Trainer::factory()->for($account)->for($trainerType)->create();
        $genericType = ClassType::factory()->for($account)->create([
            'name' => 'INDIVIDUAL',
            'schedule_kind' => 'private_lesson',
            'activity_direction_id' => null,
        ]);
        $heelsDirection = ActivityDirection::factory()->for($account)->create(['name' => 'High Heels']);
        $heelsType = ClassType::factory()->for($account)->for($heelsDirection, 'activityDirection')->create([
            'name' => 'Private High Heels',
            'schedule_kind' => 'private_lesson',
        ]);
        $jazzDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Jazz Funk']);
        $jazzType = ClassType::factory()->for($account)->for($jazzDirection, 'activityDirection')->create([
            'name' => 'Private Jazz Funk',
            'schedule_kind' => 'private_lesson',
        ]);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Five private lessons',
            'schedule_kind' => 'private_lesson',
            'price_cents' => 250000,
            'currency' => 'UAH',
            'sessions_count' => 5,
            'validity_days' => 30,
            'total_validity_days' => 180,
        ]);
        $plan->classTypes()->sync([$genericType->id]);
        $plan->trainerTypes()->sync([$trainerType->id]);
        $plan->rooms()->sync([$room->id]);

        return compact('owner', 'account', 'customer', 'location', 'room', 'trainerType', 'trainer', 'genericType', 'heelsType', 'jazzType', 'plan');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function widenPlan(array $context): void
    {
        $plan = $context['plan'];

        $this->actingAs($context['owner'])
            ->put(route('dashboard.accounts.class-pass-plans.update', [$context['account'], $plan]), [
                'name' => $plan->name,
                'slug' => $plan->slug,
                'schedule_kind' => 'private_lesson',
                'price' => '2500',
                'currency' => 'UAH',
                'sessions_count' => 5,
                'validity_days' => 30,
                'total_validity_days' => 180,
                'class_type_ids' => [$context['genericType']->id, $context['heelsType']->id, $context['jazzType']->id],
                'trainer_type_ids' => [$context['trainerType']->id],
                'room_ids' => [$context['room']->id],
                'is_active' => '1',
                'sort_order' => 10,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$context['account'], 'tab' => 'private_lesson']));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scheduledClass(array $context, ClassType $classType, string $startsAt, ?Trainer $trainer = null, ?Room $room = null): ScheduledClass
    {
        $startsAt = Carbon::parse($startsAt, 'UTC');

        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($room ?? $context['room'])
            ->for($classType)
            ->for($trainer ?? $context['trainer'])
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
                'capacity' => 1,
            ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function book(array $context, ScheduledClass $scheduledClass): ClassBooking
    {
        $this->actingAs($context['owner'])
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $scheduledClass]), [
                'customer_id' => $context['customer']->id,
            ])
            ->assertCreated();

        return $scheduledClass->classBookings()->whereBelongsTo($context['customer'])->firstOrFail();
    }
}
