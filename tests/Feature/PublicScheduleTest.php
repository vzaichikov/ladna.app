<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicScheduleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scheduled_class_display_type_labels_hide_title_duplicates_and_keep_unique_metadata(): void
    {
        $matchingDirection = new ActivityDirection(['name' => 'Pole Dance']);
        $matchingType = new ClassType(['name' => 'Pole Dance']);
        $matchingType->setRelation('activityDirection', $matchingDirection);
        $matchingClass = new ScheduledClass(['title' => 'Pole Dance']);
        $matchingClass->setRelation('classType', $matchingType);

        $this->assertSame([], $matchingClass->displayTypeLabels());

        $distinctDirection = new ActivityDirection(['name' => 'Exotic']);
        $matchingDistinctType = new ClassType(['name' => 'Exot Easy']);
        $matchingDistinctType->setRelation('activityDirection', $distinctDirection);
        $distinctClass = new ScheduledClass(['title' => 'Exot Easy']);
        $distinctClass->setRelation('classType', $matchingDistinctType);

        $this->assertSame(['Exotic'], $distinctClass->displayTypeLabels());

        $duplicateDirection = new ActivityDirection(['name' => 'Pole']);
        $duplicateType = new ClassType(['name' => 'pole']);
        $duplicateType->setRelation('activityDirection', $duplicateDirection);
        $duplicateClass = new ScheduledClass(['title' => 'Pole Flow']);
        $duplicateClass->setRelation('classType', $duplicateType);

        $this->assertSame(['Pole'], $duplicateClass->displayTypeLabels());
    }

    public function test_public_schedule_page_shows_only_public_classes_for_location(): void
    {
        $account = Account::factory()->create([
            'slug' => 'test-studio-nastya',
            'default_language' => 'en',
            'timezone' => 'Europe/Kyiv',
            'support_whatsapp_url' => 'https://wa.me/380501234567',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'test-location-1', 'name' => 'Location 1']);
        $otherLocation = Location::factory()->for($account)->create(['slug' => 'test-location-2']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Big Hall']);
        $otherRoom = Room::factory()->for($account)->for($otherLocation)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Nastya']);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'title' => 'Pole Beginner',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'title' => 'Private Staff Class',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'is_public' => false,
        ]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'title' => 'Cancelled Public Class',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => ScheduledClassStatus::Cancelled->value,
        ]);
        ScheduledClass::factory()->for($account)->for($otherLocation)->for($otherRoom)->for($classType)->for($trainer)->create([
            'title' => 'Other Location Class',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $this->get('/test-studio-nastya/test-location-1/schedule')
            ->assertOk()
            ->assertSee('Pole Beginner')
            ->assertSee('Big Hall')
            ->assertSee(__('app.powered_by_ladna'))
            ->assertSee('brand/ladna-mark.svg', false)
            ->assertSee(__('app.public_contact_title', ['studio' => $account->name]))
            ->assertSee('https://wa.me/380501234567', false)
            ->assertSee('assets/social/whatsapp.svg', false)
            ->assertDontSee('Europe/Kyiv')
            ->assertDontSee(__('app.terms_of_service'))
            ->assertDontSee('Private Staff Class')
            ->assertDontSee('Cancelled Public Class')
            ->assertDontSee('Other Location Class');
    }

    public function test_public_schedule_xhr_returns_only_schedule_fragment(): void
    {
        $account = Account::factory()->create(['slug' => 'test-fragment-studio', 'timezone' => 'UTC']);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Fragment Class',
            'starts_at' => now('UTC')->addDay(),
            'ends_at' => now('UTC')->addDay()->addHour(),
        ]);

        $this->get('/test-fragment-studio/main/schedule?period=week', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('data-public-schedule-fragment', false)
            ->assertSee('Fragment Class')
            ->assertDontSee('<main', false)
            ->assertDontSee('<html', false);

        $this->get('/test-fragment-studio/main/schedule/embed?period=week', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('data-public-schedule-fragment', false)
            ->assertSee('Fragment Class')
            ->assertDontSee('<main', false)
            ->assertDontSee('<html', false);
    }

    public function test_inactive_location_schedule_is_not_public(): void
    {
        $account = Account::factory()->create(['slug' => 'test-studio-inactive']);
        Location::factory()->for($account)->create(['slug' => 'inactive-location', 'is_active' => false]);

        $this->get('/test-studio-inactive/inactive-location/schedule')->assertNotFound();
    }

    public function test_public_schedule_hides_booking_action_after_booking_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:30:00', 'UTC'));

        $account = Account::factory()->create([
            'slug' => 'test-booking-cutoff-studio',
            'default_language' => 'uk',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => 'group_class',
            'booking_cutoff_minutes' => 60,
            'cancellation_cutoff_minutes' => 1440,
        ]);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Closed Booking Class',
            'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
        ]);

        $this->get('/test-booking-cutoff-studio/main/schedule')
            ->assertOk()
            ->assertSee(__('app.booking_cutoff_closed'))
            ->assertSee(__('app.booking_closed'))
            ->assertDontSee(route('customer.studio.login', $account->slug), false);

        Carbon::setTestNow();
    }

    public function test_suspended_account_schedule_is_not_public(): void
    {
        $account = Account::factory()->create(['slug' => 'test-suspended-studio', 'status' => 'suspended']);
        Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get('/test-suspended-studio/main/schedule')->assertNotFound();
    }

    public function test_private_lesson_and_room_rental_class_types_are_not_public(): void
    {
        $account = Account::factory()->create(['slug' => 'test-private-kind-studio']);
        $location = Location::factory()->for($account)->create(['slug' => 'main']);
        $room = Room::factory()->for($account)->for($location)->create();
        $privateType = ClassType::factory()->for($account)->create(['schedule_kind' => 'private_lesson']);
        $rentalType = ClassType::factory()->for($account)->create(['schedule_kind' => 'room_rental']);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($privateType)->create([
            'title' => 'Private Lesson',
        ]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($rentalType)->create([
            'title' => 'Room Rental',
        ]);

        $this->get('/test-private-kind-studio/main/schedule')
            ->assertOk()
            ->assertDontSee('Private Lesson')
            ->assertDontSee('Room Rental');
    }

    public function test_public_schedule_manual_mock_buttons_follow_enabled_schedule_kinds(): void
    {
        $account = Account::factory()->create([
            'slug' => 'test-enabled-public-cta-studio',
            'default_language' => 'uk',
            'enabled_schedule_kinds' => [
                ScheduleKind::GroupClass->value,
                ScheduleKind::PrivateLesson->value,
            ],
        ]);
        Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get('/test-enabled-public-cta-studio/main/schedule')
            ->assertOk()
            ->assertSee('Запис на індивідуальне')
            ->assertDontSee('Орендувати зал');
    }

    public function test_public_schedule_shows_available_slots_and_disables_full_classes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $account = Account::factory()->create([
            'slug' => 'test-full-public-class-studio',
            'default_language' => 'uk',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Full Public Class',
            'starts_at' => Carbon::parse('2026-06-18 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-18 11:00:00', 'UTC'),
            'capacity' => 1,
        ]);
        $customer = Customer::factory()->for($account)->create();

        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Booked->value]);

        $this->get('/test-full-public-class-studio/main/schedule')
            ->assertOk()
            ->assertSee(__('app.available_slots'))
            ->assertSee(__('app.no_available_group_slots'))
            ->assertSee(__('app.booking_full'))
            ->assertDontSee(route('customer.studio.login', $account->slug), false);

        Carbon::setTestNow();
    }

    public function test_public_schedule_shows_logged_in_customer_passes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $account = Account::factory()->create([
            'slug' => 'test-customer-public-schedule-studio',
            'default_language' => 'uk',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Customer Visible Class',
            'starts_at' => Carbon::parse('2026-06-18 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-18 11:00:00', 'UTC'),
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Olena Client']);
        $classPassPlan = ClassPassPlan::factory()->for($account)->create();

        CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($classPassPlan)
            ->create([
                'code' => 'ABCD-1234',
                'plan_name' => 'BASE 8',
                'sessions_count' => 8,
                'reserved_sessions_count' => 1,
                'used_sessions_count' => 2,
            ]);

        $this->actingAs($customer, 'customer')
            ->get('/test-customer-public-schedule-studio/main/schedule')
            ->assertOk()
            ->assertSee('Ви увійшли як Olena Client')
            ->assertSee('BASE 8')
            ->assertSee('ABCD-1234')
            ->assertSee(route('customer.dashboard', $account->slug), false);

        Carbon::setTestNow();
    }

    public function test_account_default_language_affects_public_schedule_without_session_locale(): void
    {
        $account = Account::factory()->create([
            'slug' => 'test-english-studio',
            'default_language' => 'en',
        ]);
        Location::factory()->for($account)->create(['slug' => 'main']);

        $this->get('/test-english-studio/main/schedule')
            ->assertOk()
            ->assertSee('No classes yet.');
    }

    public function test_ukrainian_public_schedule_uses_localized_day_names(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        $account = Account::factory()->create([
            'slug' => 'test-ukrainian-studio',
            'default_language' => 'uk',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainer = Trainer::factory()->for($account)->create();

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->for($trainer)->create([
            'title' => 'Pole Ukrainian Date',
            'starts_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-17 11:00:00', 'UTC'),
        ]);

        $this->get('/test-ukrainian-studio/main/schedule')
            ->assertOk()
            ->assertSee('середа, 17 червня')
            ->assertDontSee('Wed');

        Carbon::setTestNow();
    }

    public function test_public_schedule_month_period_includes_thirty_days_with_full_date_links(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 09:00:00', 'UTC'));

        $account = Account::factory()->create([
            'slug' => 'test-month-public-schedule-studio',
            'default_language' => 'uk',
            'timezone' => 'UTC',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);

        ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create([
            'title' => 'Thirty Day Class',
            'starts_at' => Carbon::parse('2026-07-25 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-07-25 11:00:00', 'UTC'),
        ]);

        $this->get('/test-month-public-schedule-studio/main/schedule')
            ->assertOk()
            ->assertDontSee('Thirty Day Class');

        $this->get('/test-month-public-schedule-studio/main/schedule?period=month')
            ->assertOk()
            ->assertSee('Thirty Day Class')
            ->assertSee('субота, 25 липня');

        Carbon::setTestNow();
    }
}
