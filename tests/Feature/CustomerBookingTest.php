<?php

namespace Tests\Feature;

use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerBookingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_scheduled_classes_index(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'schedule_kind_colors' => [
                'group_class' => '#FF00AA',
                'private_lesson' => '#A78AB9',
                'room_rental' => '#38BDF8',
            ],
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $activityDirection = ActivityDirection::factory()->for($account)->create(['color' => '#C7F000']);
        $classType = ClassType::factory()
            ->for($account)
            ->for($activityDirection)
            ->create(['color' => '#C7F000']);

        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Pole Beginner',
                'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
            ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account));

        $response
            ->assertOk()
            ->assertSee('Pole Beginner')
            ->assertSee(__('app.add_class_record_short'))
            ->assertSee(route('dashboard.accounts.schedule-series.index', $account), false)
            ->assertSee(route('dashboard.accounts.scheduled-classes-history.index', $account), false)
            ->assertSee('data-schedule-secondary-actions', false)
            ->assertSee('data-schedule-primary-actions', false)
            ->assertSee('data-manual-class-open="group_class"', false)
            ->assertDontSee('data-manual-class-open="private_lesson"', false)
            ->assertDontSee('data-manual-class-open="room_rental"', false)
            ->assertDontSee('app.add_group_class_class_record')
            ->assertSee('background-color: #C7F000;', false)
            ->assertSee('border-right-color: #FF00AA;', false)
            ->assertSee('color: #1E293B;', false);

        $this->assertLessThan(
            mb_strpos($response->getContent(), 'data-manual-class-open="group_class"'),
            mb_strpos($response->getContent(), route('dashboard.accounts.schedule-series.index', $account))
        );
        $this->assertLessThan(
            mb_strpos($response->getContent(), 'data-schedule-primary-actions'),
            mb_strpos($response->getContent(), 'data-schedule-secondary-actions')
        );

        Carbon::setTestNow();
    }

    public function test_scheduled_classes_index_shows_temporal_status_badges(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();

        $this->scheduledClassWithTimes(
            $account,
            $location,
            $room,
            $classType,
            'Recently Ended Class',
            Carbon::parse('2026-06-17 10:45:00', 'UTC'),
            Carbon::parse('2026-06-17 11:15:00', 'UTC'),
        );
        $liveClass = $this->scheduledClassWithTimes(
            $account,
            $location,
            $room,
            $classType,
            'Live Status Class',
            Carbon::parse('2026-06-17 11:30:00', 'UTC'),
            Carbon::parse('2026-06-17 12:30:00', 'UTC'),
        );
        $this->scheduledClassWithTimes(
            $account,
            $location,
            $room,
            $classType,
            'Future Status Class',
            Carbon::parse('2026-06-17 13:00:00', 'UTC'),
            Carbon::parse('2026-06-17 14:00:00', 'UTC'),
        );
        $this->scheduledClassWithTimes(
            $account,
            $location,
            $room,
            $classType,
            'Cancelled Status Class',
            Carbon::parse('2026-06-17 14:00:00', 'UTC'),
            Carbon::parse('2026-06-17 15:00:00', 'UTC'),
            ScheduledClassStatus::Cancelled,
        );
        $this->scheduledClassWithTimes(
            $account,
            $location,
            $room,
            $classType,
            'Draft Status Class',
            Carbon::parse('2026-06-17 15:00:00', 'UTC'),
            Carbon::parse('2026-06-17 16:00:00', 'UTC'),
            ScheduledClassStatus::Draft,
        );

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSeeInOrder([
                'Recently Ended Class',
                __('app.ended'),
                'Live Status Class',
                __('app.in_progress'),
                'Future Status Class',
                __('app.scheduled'),
                'Cancelled Status Class',
                __('app.cancelled'),
                'Draft Status Class',
                __('app.draft'),
            ])
            ->assertSee('<span class="crm-status-muted">'.__('app.ended').'</span>', false)
            ->assertSee('<span class="crm-status-active">'.__('app.in_progress').'</span>', false)
            ->assertSee('<span class="crm-status-scheduled">'.__('app.scheduled').'</span>', false)
            ->assertSee('<span class="crm-status-danger">'.__('app.cancelled').'</span>', false);

        $this->assertSame(ScheduledClassStatus::Scheduled, $liveClass->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_scheduled_classes_index_temporal_status_uses_real_time_for_non_utc_studio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'Europe/Kyiv']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();

        $this->scheduledClassWithTimes(
            $account,
            $location,
            $room,
            $classType,
            'Kyiv Live Status Class',
            Carbon::parse('2026-06-17 12:00:00', 'Europe/Kyiv')->timezone('UTC'),
            Carbon::parse('2026-06-17 13:00:00', 'Europe/Kyiv')->timezone('UTC'),
        );

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('Kyiv Live Status Class')
            ->assertSee('<span class="crm-status-active">'.__('app.in_progress').'</span>', false);

        Carbon::setTestNow();
    }

    public function test_owner_can_create_customer_book_class_and_mark_attendance(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['cancellation_cutoff_minutes' => null]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.customers.store', $account), [
                'name' => 'Олена',
                'phone' => '+380671112233',
                'email' => 'olena@example.com',
                'default_language' => 'uk',
            ])
            ->assertRedirect(route('dashboard.accounts.customers.index', $account));

        $customer = Customer::whereBelongsTo($account)->where('email', 'olena@example.com')->firstOrFail();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
                'notes' => 'First visit',
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account));

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.bookings.update', [$account, $booking]), [
                'status' => 'attended',
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account));

        $booking->refresh();
        $this->assertSame('attended', $booking->status->value);
        $this->assertNotNull($booking->attended_at);
    }

    public function test_owner_can_create_booking_with_json_response(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['cancellation_cutoff_minutes' => null]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Pole Beginner',
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Олена Коваль']);

        $response = $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
                'notes' => 'First visit',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', __('app.booking_created'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);

        $this->assertStringContainsString('data-scheduled-class-card', $response->json('card_html'));
        $this->assertStringContainsString('Олена Коваль', $response->json('card_html'));

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();
        $this->assertSame('First visit', $booking->notes);
    }

    public function test_owner_cannot_create_standalone_private_lesson_or_rental_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 6]);
        $privateClassType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => 'private_lesson',
            'default_duration_minutes' => 60,
            'default_capacity' => 2,
        ]);
        $rentalClassType = ClassType::factory()->for($account)->create([
            'name' => 'Rental 60',
            'schedule_kind' => 'room_rental',
            'default_duration_minutes' => 60,
            'default_capacity' => 2,
        ]);
        $trainer = Trainer::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.manual.store', [$account, 'private_lesson']), [
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $privateClassType->id,
                'trainer_id' => $trainer->id,
                'starts_at' => '2026-06-17T15:00',
                'capacity' => 2,
            ])
            ->assertSessionHasErrors(['class_type_id' => __('app.manual_class_format_invalid')]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.manual.store', [$account, 'room_rental']), [
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $rentalClassType->id,
                'starts_at' => '2026-06-17T15:00',
                'capacity' => 2,
            ])
            ->assertSessionHasErrors(['class_type_id' => __('app.manual_class_format_invalid')]);

        $this->assertFalse(ScheduledClass::whereBelongsTo($account)->whereIn('title', ['Private 60', 'Rental 60'])->exists());

        Carbon::setTestNow();
    }

    public function test_owner_can_create_manual_group_class_record_and_generation_keeps_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 12]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Replacement Group',
            'schedule_kind' => 'group_class',
            'default_duration_minutes' => 75,
            'default_capacity' => 10,
        ]);
        $trainer = Trainer::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.manual.store', [$account, 'group_class']), [
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'title' => 'Sick Coach Replacement',
                'starts_at' => '2026-06-17T15:00',
                'duration_minutes' => 75,
                'capacity' => 9,
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account));

        $manualClass = ScheduledClass::whereBelongsTo($account)
            ->where('title', 'Sick Coach Replacement')
            ->firstOrFail();

        $this->assertFalse($manualClass->is_generated);
        $this->assertTrue($manualClass->is_public);
        $this->assertNull($manualClass->schedule_series_id);
        $this->assertSame('group_class', $manualClass->metadata['schedule_kind']);
        $this->assertSame(9, $manualClass->capacity);
        $this->assertSame(75, $manualClass->durationMinutes());

        ScheduleSeries::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'weekday' => Carbon::parse('2026-06-17 09:00:00', 'UTC')->isoWeekday(),
                'start_time' => '12:00',
                'start_date' => '2026-06-17',
            ]);

        $this->artisan('schedule:generate')->assertSuccessful();

        $this->assertModelExists($manualClass);
        $this->assertNotNull($manualClass->fresh());

        Carbon::setTestNow();
    }

    public function test_private_lesson_booking_allows_only_one_active_customer(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'private_lesson']);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create(['capacity' => 2]);
        $firstCustomer = Customer::factory()->for($account)->create();
        $secondCustomer = Customer::factory()->for($account)->create();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $firstCustomer->id,
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $secondCustomer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', __('app.manual_class_already_booked'));

        $this->assertSame(1, $scheduledClass->classBookings()->count());
    }

    public function test_owner_can_update_booking_status_with_json_response(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();
        $customer = Customer::factory()->for($account)->create(['name' => 'Марія Шевченко']);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create(['status' => 'booked', 'attended_at' => null]);

        $response = $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.bookings.update', [$account, $booking]), [
                'status' => 'attended',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', __('app.booking_updated'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);

        $this->assertStringContainsString('Марія Шевченко', $response->json('card_html'));
        $this->assertStringContainsString(__('app.attended'), $response->json('card_html'));

        $booking->refresh();
        $this->assertSame('attended', $booking->status->value);
        $this->assertNotNull($booking->attended_at);
    }

    public function test_owner_can_delete_booking_with_json_response(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['cancellation_cutoff_minutes' => null]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();
        $customer = Customer::factory()->for($account)->create(['name' => 'Ірина Мельник']);
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create();

        $response = $this->actingAs($owner)
            ->deleteJson(route('dashboard.accounts.bookings.destroy', [$account, $booking]));

        $response
            ->assertOk()
            ->assertJsonPath('message', __('app.booking_deleted'))
            ->assertJsonPath('scheduled_class_id', $scheduledClass->id);

        $this->assertModelMissing($booking);
        $this->assertStringContainsString('data-scheduled-class-card', $response->json('card_html'));
        $this->assertStringNotContainsString('Ірина Мельник', $response->json('card_html'));
    }

    public function test_booking_cutoff_does_not_block_admin_delete_before_cancellation_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'booking_cutoff_minutes' => 60,
            'cancellation_cutoff_minutes' => 10,
        ]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
        ]);
        $customer = Customer::factory()->for($account)->create();
        $booking = ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create();

        $this->actingAs($owner)
            ->deleteJson(route('dashboard.accounts.bookings.destroy', [$account, $booking]))
            ->assertOk();

        $this->assertModelMissing($booking);

        Carbon::setTestNow();
    }

    public function test_owner_cannot_delete_booking_inside_cancellation_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['booking_cutoff_minutes' => 5, 'cancellation_cutoff_minutes' => 60]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Locked Class',
            'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Locked Client']);
        $booking = ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create();

        $this->actingAs($owner)
            ->deleteJson(route('dashboard.accounts.bookings.destroy', [$account, $booking]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.booking.0', __('app.booking_cancellation_cutoff_locked'));

        $this->assertModelExists($booking);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee(__('app.booking_cancellation_cutoff_marker'))
            ->assertDontSee('data-confirm-delete', false)
            ->assertDontSee('value="DELETE"', false);

        Carbon::setTestNow();
    }

    public function test_cancelled_status_is_blocked_inside_cutoff_but_operational_statuses_remain_allowed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['booking_cutoff_minutes' => 5, 'cancellation_cutoff_minutes' => 60]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
        ]);
        $customer = Customer::factory()->for($account)->create();
        $booking = ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create();

        $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.bookings.update', [$account, $booking]), ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', __('app.booking_cancellation_cutoff_locked'));

        $this->assertSame('booked', $booking->fresh()->status->value);

        $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.bookings.update', [$account, $booking]), ['status' => 'attended'])
            ->assertOk();

        $this->assertSame('attended', $booking->fresh()->status->value);

        $this->actingAs($owner)
            ->patchJson(route('dashboard.accounts.bookings.update', [$account, $booking]), ['status' => 'no_show'])
            ->assertOk();

        $this->assertSame('no_show', $booking->fresh()->status->value);

        Carbon::setTestNow();
    }

    public function test_customer_can_cancel_own_upcoming_booking_before_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 08:30:00', 'UTC'));

        $account = Account::factory()->create(['timezone' => 'UTC', 'slug' => 'customer-cancel-before-cutoff']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['booking_cutoff_minutes' => 5, 'cancellation_cutoff_minutes' => 60]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Customer Cancel Class',
            'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Client One', 'phone' => '+380501111111']);
        $booking = ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create();

        $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee(route('customer.bookings.cancel', [$account->slug, $booking]), false);

        $this->actingAs($customer, 'customer')
            ->patch(route('customer.bookings.cancel', [$account->slug, $booking]))
            ->assertRedirect(route('customer.dashboard', $account->slug))
            ->assertSessionHas('status', __('app.customer_booking_cancelled'));

        $this->assertSame('cancelled', $booking->fresh()->status->value);

        Carbon::setTestNow();
    }

    public function test_customer_cannot_cancel_booking_inside_cutoff_or_for_another_customer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:30:00', 'UTC'));

        $account = Account::factory()->create(['timezone' => 'UTC', 'slug' => 'customer-cancel-locked']);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['booking_cutoff_minutes' => 5, 'cancellation_cutoff_minutes' => 60]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Locked Customer Class',
            'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
        ]);
        $customer = Customer::factory()->for($account)->create(['phone' => '+380501111112']);
        $otherCustomer = Customer::factory()->for($account)->create(['phone' => '+380501111113']);
        $booking = ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create();

        $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee(__('app.booking_cancellation_cutoff_marker'))
            ->assertDontSee(route('customer.bookings.cancel', [$account->slug, $booking]), false);

        $this->actingAs($customer, 'customer')
            ->patch(route('customer.bookings.cancel', [$account->slug, $booking]))
            ->assertSessionHasErrors('booking');

        $this->assertSame('booked', $booking->fresh()->status->value);

        $this->actingAs($otherCustomer, 'customer')
            ->patch(route('customer.bookings.cancel', [$account->slug, $booking]))
            ->assertNotFound();

        Carbon::setTestNow();
    }

    public function test_customer_visit_history_uses_studio_timezone_in_admin_and_customer_portal(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'slug' => 'visit-timezone-'.fake()->unique()->numberBetween(1000, 9999),
            'timezone' => 'America/New_York',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'America/New_York']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['name' => 'Timezone Pole']);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Timezone Visit Class',
            'starts_at' => Carbon::parse('2026-06-20 02:30:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-20 03:30:00', 'UTC'),
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Timezone Customer']);
        ClassBooking::factory()->for($account)->for($scheduledClass)->for($customer)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.edit', [$account, $customer]))
            ->assertOk()
            ->assertSee('Timezone Visit Class')
            ->assertSee('2026-06-19 22:30')
            ->assertDontSee('2026-06-20 02:30');

        $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard', $account->slug))
            ->assertOk()
            ->assertSee('Timezone Visit Class')
            ->assertSee('2026-06-19 22:30')
            ->assertDontSee('2026-06-20 02:30');
    }

    public function test_booking_rejects_customer_from_another_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $otherCustomer->id,
            ])
            ->assertInvalid('customer_id');
    }

    public function test_json_booking_rejects_customer_from_another_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();
        $otherCustomer = Customer::factory()->for($otherAccount)->create();

        $response = $this->actingAs($owner)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $otherCustomer->id,
            ]);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('customer_id', $payload['errors']);
    }

    public function test_scheduled_class_tabs_filter_by_account_and_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $otherLocation = Location::factory()->for($otherAccount)->create(['timezone' => 'UTC']);
        $otherRoom = Room::factory()->for($otherAccount)->for($otherLocation)->create();
        $otherClassType = ClassType::factory()->for($otherAccount)->create();

        $this->scheduledClass($account, $location, $room, $classType, 'Today Class', '2026-06-17 10:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Tomorrow Class', '2026-06-18 10:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Sunday Class', '2026-06-21 10:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Next Monday Class', '2026-06-22 10:00:00');
        $this->scheduledClass($otherAccount, $otherLocation, $otherRoom, $otherClassType, 'Other Account Class', '2026-06-17 10:00:00');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('Today Class')
            ->assertDontSee('Tomorrow Class')
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => 'this_week']))
            ->assertOk()
            ->assertSee('Today Class')
            ->assertSee('Tomorrow Class')
            ->assertSee('Sunday Class')
            ->assertDontSee('Next Monday Class')
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => 'next_week']))
            ->assertOk()
            ->assertSee('Next Monday Class')
            ->assertDontSee('Sunday Class')
            ->assertDontSee('Other Account Class');

        Carbon::setTestNow();
    }

    public function test_scheduled_classes_can_be_filtered_by_locations_and_rooms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create(['name' => 'First Studio', 'timezone' => 'UTC']);
        $secondLocation = Location::factory()->for($account)->create(['name' => 'Second Studio', 'timezone' => 'UTC']);
        $firstRoom = Room::factory()->for($account)->for($firstLocation)->create(['name' => 'Blue Room']);
        $secondRoom = Room::factory()->for($account)->for($secondLocation)->create(['name' => 'Pink Room']);
        $classType = ClassType::factory()->for($account)->create();
        $otherLocation = Location::factory()->for($otherAccount)->create(['timezone' => 'UTC']);
        $otherRoom = Room::factory()->for($otherAccount)->for($otherLocation)->create();
        $otherClassType = ClassType::factory()->for($otherAccount)->create();
        $firstClass = $this->scheduledClass($account, $firstLocation, $firstRoom, $classType, 'First Location Class', '2026-06-17 10:00:00');
        $this->scheduledClass($account, $secondLocation, $secondRoom, $classType, 'Second Room Class', '2026-06-17 11:00:00');
        $this->scheduledClass($otherAccount, $otherLocation, $otherRoom, $otherClassType, 'Other Account Class', '2026-06-17 12:00:00');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => 'this_week']))
            ->assertOk()
            ->assertSee('First Location Class')
            ->assertSee('Second Room Class')
            ->assertSee('href="#scheduled-class-'.$firstClass->id.'"', false)
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', [
                'account' => $account,
                'tab' => 'this_week',
                'locations' => [$firstLocation->id],
            ]))
            ->assertOk()
            ->assertSee('First Location Class')
            ->assertDontSee('Second Room Class')
            ->assertDontSee('Other Account Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', [
                'account' => $account,
                'tab' => 'this_week',
                'rooms' => [$secondRoom->id],
            ]))
            ->assertOk()
            ->assertSee('Second Room Class')
            ->assertDontSee('First Location Class')
            ->assertDontSee('Other Account Class');

        Carbon::setTestNow();
    }

    public function test_scheduled_class_history_filters_by_date_locations_and_rooms_in_readonly_mode(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-26 10:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create(['name' => 'First Studio', 'timezone' => 'UTC']);
        $secondLocation = Location::factory()->for($account)->create(['name' => 'Second Studio', 'timezone' => 'UTC']);
        $firstRoom = Room::factory()->for($account)->for($firstLocation)->create(['name' => 'Blue Room']);
        $secondRoom = Room::factory()->for($account)->for($secondLocation)->create(['name' => 'Pink Room']);
        $classType = ClassType::factory()->for($account)->create();
        $otherLocation = Location::factory()->for($otherAccount)->create(['timezone' => 'UTC']);
        $otherRoom = Room::factory()->for($otherAccount)->for($otherLocation)->create();
        $otherClassType = ClassType::factory()->for($otherAccount)->create();
        $firstClass = $this->scheduledClass($account, $firstLocation, $firstRoom, $classType, 'History First Class', '2026-06-25 10:00:00');
        $booking = ClassBooking::factory()
            ->for($account)
            ->for($firstClass)
            ->for(Customer::factory()->for($account)->create(['name' => 'Readonly Customer']))
            ->create(['booked_by_user_id' => null]);
        $this->scheduledClass($account, $secondLocation, $secondRoom, $classType, 'History Second Room Class', '2026-06-25 11:00:00');
        $this->scheduledClass($account, $firstLocation, $firstRoom, $classType, 'Different Date Class', '2026-06-24 10:00:00');
        $this->scheduledClass($account, $firstLocation, $firstRoom, $classType, 'Future Date Class', '2026-06-27 10:00:00');
        $this->scheduledClass($otherAccount, $otherLocation, $otherRoom, $otherClassType, 'Other Account Class', '2026-06-25 10:00:00');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                'account' => $account,
                'date' => '2026-06-25',
            ]))
            ->assertOk()
            ->assertSee('History First Class')
            ->assertSee('History Second Room Class')
            ->assertSee('Readonly Customer')
            ->assertDontSee('Different Date Class')
            ->assertDontSee('Future Date Class')
            ->assertDontSee('Other Account Class')
            ->assertDontSee(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $firstClass]), false)
            ->assertDontSee(route('dashboard.accounts.bookings.update', [$account, $booking]), false)
            ->assertDontSee(route('dashboard.accounts.scheduled-classes.cancel', [$account, $firstClass]), false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                'account' => $account,
                'date' => '2026-06-25',
                'locations' => [$firstLocation->id],
            ]))
            ->assertOk()
            ->assertSee('History First Class')
            ->assertDontSee('History Second Room Class');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes-history.index', [
                'account' => $account,
                'date' => '2026-06-25',
                'rooms' => [$secondRoom->id],
            ]))
            ->assertOk()
            ->assertSee('History Second Room Class')
            ->assertDontSee('History First Class');

        Carbon::setTestNow();
    }

    public function test_today_tab_collapses_classes_ended_more_than_one_hour_ago(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();

        $this->scheduledClass($account, $location, $room, $classType, 'Old Hidden Class', '2026-06-17 09:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Cutoff Visible Class', '2026-06-17 10:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Future Visible Class', '2026-06-17 11:15:00');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('data-scheduled-class-history', false)
            ->assertSeeInOrder([
                'Cutoff Visible Class',
                'Future Visible Class',
                __('app.older_today_classes'),
                'Old Hidden Class',
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => 'this_week']))
            ->assertOk()
            ->assertDontSee('data-scheduled-class-history', false)
            ->assertSeeInOrder([
                'Old Hidden Class',
                'Cutoff Visible Class',
                'Future Visible Class',
            ]);

        Carbon::setTestNow();
    }

    public function test_today_tab_uses_account_timezone_for_past_grouping(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 03:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'America/New_York']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'America/New_York']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();

        $this->scheduledClass($account, $location, $room, $classType, 'Old Local Hidden Class', '2026-06-17 01:00:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Cutoff Local Visible Class', '2026-06-17 01:30:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Future Local Visible Class', '2026-06-17 03:45:00');
        $this->scheduledClass($account, $location, $room, $classType, 'Next Local Day Class', '2026-06-17 04:30:00');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('data-scheduled-class-history', false)
            ->assertSeeInOrder([
                'Cutoff Local Visible Class',
                'Future Local Visible Class',
                __('app.older_today_classes'),
                'Old Local Hidden Class',
            ])
            ->assertDontSee('Next Local Day Class');

        Carbon::setTestNow();
    }

    public function test_customer_search_matches_same_account_customer_fields(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        Customer::factory()->for($account)->create([
            'name' => 'Олена Коваль',
            'phone' => '+380671112233',
            'email' => 'olena.koval@example.com',
        ]);
        Customer::factory()->for($account)->create([
            'name' => 'Марія Шевченко',
            'phone' => '+380501234567',
            'email' => 'maria@example.com',
        ]);
        Customer::factory()->for($otherAccount)->create([
            'name' => 'Олена Інша',
            'phone' => '+380671112233',
            'email' => 'other@example.com',
        ]);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.customers.search', ['account' => $account, 'q' => 'olena']))
            ->assertOk()
            ->assertJsonFragment(['email' => 'olena.koval@example.com'])
            ->assertJsonMissing(['email' => 'other@example.com']);

        $this->actingAs($owner)
            ->getJson(route('dashboard.accounts.customers.search', ['account' => $account, 'q' => '1234567']))
            ->assertOk()
            ->assertJsonFragment(['email' => 'maria@example.com']);
    }

    private function scheduledClass(Account $account, Location $location, Room $room, ClassType $classType, string $title, string $startsAt): ScheduledClass
    {
        $startsAt = Carbon::parse($startsAt, 'UTC');

        return $this->scheduledClassWithTimes(
            $account,
            $location,
            $room,
            $classType,
            $title,
            $startsAt,
            $startsAt->copy()->addHour(),
        );
    }

    private function scheduledClassWithTimes(
        Account $account,
        Location $location,
        Room $room,
        ClassType $classType,
        string $title,
        Carbon $startsAt,
        Carbon $endsAt,
        ScheduledClassStatus $status = ScheduledClassStatus::Scheduled,
    ): ScheduledClass {
        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status->value,
            ]);
    }
}
