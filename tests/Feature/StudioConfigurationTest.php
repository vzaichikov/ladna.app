<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StudioConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_room_slug_is_unique_within_location_not_globally(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $locationOne = Location::factory()->for($account)->create();
        $locationTwo = Location::factory()->for($account)->create();
        Room::factory()->for($account)->for($locationOne)->create(['slug' => 'big-hall']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.rooms.store', $account), [
                'location_id' => $locationTwo->id,
                'name' => 'Big Hall',
                'slug' => 'big-hall',
                'capacity' => 12,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $this->assertTrue(Room::whereBelongsTo($locationTwo)->where('slug', 'big-hall')->exists());
    }

    public function test_owner_cannot_manage_room_outside_owned_account(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherAccount->addOwner($otherOwner);
        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherRoom = Room::factory()->for($otherAccount)->for($otherLocation)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.rooms.edit', [$account, $otherRoom]))
            ->assertNotFound();
    }

    public function test_session_locale_changes_internal_ui_language(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->actingAs($owner)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_owner_can_delete_owned_room(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.rooms.destroy', [$account, $room]))
            ->assertRedirect(route('dashboard.accounts.rooms.index', $account));

        $this->assertModelMissing($room);
    }

    public function test_deleting_class_type_removes_its_scheduled_classes(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($classType)->create();

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.group-classes.destroy', [$account, $classType]))
            ->assertRedirect(route('dashboard.accounts.group-classes.index', $account));

        $this->assertModelMissing($classType);
        $this->assertModelMissing($scheduledClass);
    }

    public function test_cyrillic_slugs_are_normalized_and_suffixed_within_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.activity-directions.store', $account), [
                'name' => 'Силова група',
                'slug' => 'Танці для початківців',
                'color' => '#A78AB9',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.activity-directions.index', $account));

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.activity-directions.store', $account), [
                'name' => 'Силова група друга',
                'slug' => 'Танці для початківців',
                'color' => '#A78AB9',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.activity-directions.index', $account));

        $this->assertTrue(ActivityDirection::whereBelongsTo($account)->where('slug', 'tantsi-dlya-pochatkivtsiv')->exists());
        $this->assertTrue(ActivityDirection::whereBelongsTo($account)->where('slug', 'tantsi-dlya-pochatkivtsiv-2')->exists());
    }

    public function test_activity_direction_color_picker_and_color_helpers(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.activity-directions.create', $account))
            ->assertOk()
            ->assertSee('data-color-picker', false)
            ->assertSee('data-color-value', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.activity-directions.store', $account), [
                'name' => 'Bright Direction',
                'slug' => 'bright-direction',
                'color' => '#C7F000',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.activity-directions.index', $account));

        $activityDirection = ActivityDirection::whereBelongsTo($account)
            ->where('slug', 'bright-direction')
            ->firstOrFail();

        $this->assertSame('#C7F000', $activityDirection->colorAccent());
        $this->assertSame('#1E293B', $activityDirection->colorText());
        $this->assertSame('#3B223F', (new ActivityDirection)->colorAccent());
    }

    public function test_class_type_color_picker_is_available_and_persists_color(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $activityDirection = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.group-classes.create', $account))
            ->assertOk()
            ->assertSee('data-color-picker', false)
            ->assertSee('data-color-value', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.group-classes.store', $account), [
                'activity_direction_id' => $activityDirection->id,
                'name' => 'Bright Format',
                'slug' => 'bright-format',
                'color' => '#123ABC',
                'schedule_kind' => 'private_lesson',
                'default_duration_minutes' => 60,
                'booking_cutoff_minutes' => 120,
                'default_capacity' => 12,
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.group-classes.index', $account));

        $classType = ClassType::whereBelongsTo($account)
            ->where('slug', 'bright-format')
            ->firstOrFail();

        $this->assertSame('#123ABC', $classType->color);
        $this->assertSame('group_class', $classType->schedule_kind->value);
    }

    public function test_class_type_sections_are_split_by_schedule_kind(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $groupType = ClassType::factory()->for($account)->create(['name' => 'Group Pole', 'schedule_kind' => 'group_class']);
        $privateType = ClassType::factory()->for($account)->create(['name' => 'Private Pole', 'schedule_kind' => 'private_lesson']);
        $rentalType = ClassType::factory()->for($account)->create(['name' => 'Rental 60', 'schedule_kind' => 'room_rental']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.group-classes.index', $account))
            ->assertOk()
            ->assertSee($groupType->name)
            ->assertDontSee($privateType->name)
            ->assertDontSee($rentalType->name);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.private-lessons.index', $account))
            ->assertOk()
            ->assertSee($privateType->name)
            ->assertDontSee($groupType->name)
            ->assertDontSee($rentalType->name);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.room-rentals.index', $account))
            ->assertOk()
            ->assertSee($rentalType->name)
            ->assertDontSee($groupType->name)
            ->assertDontSee($privateType->name);
    }

    public function test_brand_settings_persist_enabled_schedule_kinds(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'formats']))
            ->assertOk()
            ->assertSee(__('app.studio_class_formats'))
            ->assertSee(__('app.schedule_format_color'))
            ->assertSee('data-color-picker', false)
            ->assertSee('data-color-value', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                'brand_tab' => 'formats',
                'name' => $account->name,
                'slug' => $account->slug,
                'default_language' => 'uk',
                'country_code' => 'UA',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'Europe/Kyiv',
                'enabled_schedule_kinds_present' => '1',
                'enabled_schedule_kinds' => ['group_class', 'private_lesson'],
                'schedule_kind_colors_present' => '1',
                'schedule_kind_colors' => [
                    'group_class' => '#FF00AA',
                    'private_lesson' => '#00AAFF',
                    'room_rental' => '#AAFF00',
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'formats']));

        $account->refresh();

        $this->assertSame(['group_class', 'private_lesson'], $account->enabledScheduleKindValues());
        $this->assertSame('#FF00AA', $account->scheduleKindColor('group_class'));
        $this->assertSame('#00AAFF', $account->scheduleKindColor('private_lesson'));
        $this->assertSame('#AAFF00', $account->scheduleKindColor('room_rental'));
    }

    public function test_brand_settings_persist_opening_hours(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'opening_hours']))
            ->assertOk()
            ->assertSee(__('app.opening_hours'))
            ->assertSee('opening_hours_present', false);

        $openingHours = [
            1 => ['enabled' => '1', 'opens_at' => '09:00', 'closes_at' => '21:00'],
            2 => ['enabled' => '0', 'opens_at' => '09:00', 'closes_at' => '21:00'],
            3 => ['enabled' => '1', 'opens_at' => '10:00', 'closes_at' => '20:00'],
            4 => ['enabled' => '1', 'opens_at' => '10:00', 'closes_at' => '20:00'],
            5 => ['enabled' => '1', 'opens_at' => '10:00', 'closes_at' => '20:00'],
            6 => ['enabled' => '1', 'opens_at' => '11:00', 'closes_at' => '18:00'],
            7 => ['enabled' => '0', 'opens_at' => '11:00', 'closes_at' => '18:00'],
        ];

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                'brand_tab' => 'opening_hours',
                'name' => $account->name,
                'slug' => $account->slug,
                'default_language' => 'uk',
                'country_code' => 'UA',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'Europe/Kyiv',
                'opening_hours_present' => '1',
                'opening_hours' => $openingHours,
            ])
            ->assertRedirect(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'opening_hours']));

        $account->refresh();

        $this->assertSame('09:00', $account->openingHours()[1]['opens_at']);
        $this->assertSame('21:00', $account->openingHours()[1]['closes_at']);
        $this->assertFalse($account->openingHours()[2]['enabled']);
        $this->assertFalse($account->openingHours()[7]['enabled']);
    }

    public function test_brand_settings_reject_invalid_opening_hours(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'opening_hours']))
            ->put(route('dashboard.accounts.update', $account), [
                'brand_tab' => 'opening_hours',
                'name' => $account->name,
                'slug' => $account->slug,
                'default_language' => 'uk',
                'country_code' => 'UA',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'Europe/Kyiv',
                'opening_hours_present' => '1',
                'opening_hours' => [
                    1 => ['enabled' => '1', 'opens_at' => '18:00', 'closes_at' => '10:00'],
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'opening_hours']))
            ->assertSessionHasErrors('opening_hours.1.closes_at');
    }

    public function test_configuration_entities_can_be_copied_with_localized_prefix(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $activityDirection = ActivityDirection::factory()->for($account)->create([
            'name' => 'Pole',
            'slug' => 'pole',
            'description' => 'Direction description.',
            'color' => '#A78AB9',
            'is_active' => true,
        ]);
        $classType = ClassType::factory()
            ->for($account)
            ->for($activityDirection, 'activityDirection')
            ->create([
                'name' => 'Beginner',
                'slug' => 'beginner',
                'color' => '#123ABC',
                'default_duration_minutes' => 75,
                'booking_cutoff_minutes' => 60,
                'default_capacity' => 8,
                'is_active' => false,
            ]);

        $this->actingAs($owner)
            ->withSession(['locale' => 'uk'])
            ->post(route('dashboard.accounts.activity-directions.copy', [$account, $activityDirection]))
            ->assertRedirect(route('dashboard.accounts.activity-directions.index', $account));

        $copiedDirection = ActivityDirection::whereBelongsTo($account)
            ->where('slug', 'kopiya-pole')
            ->firstOrFail();

        $this->assertSame('Копія Pole', $copiedDirection->name);
        $this->assertSame('Direction description.', $copiedDirection->description);
        $this->assertSame('#A78AB9', $copiedDirection->color);
        $this->assertTrue($copiedDirection->is_active);

        $this->actingAs($owner)
            ->withSession(['locale' => 'uk'])
            ->post(route('dashboard.accounts.group-classes.copy', [$account, $classType]))
            ->assertRedirect(route('dashboard.accounts.group-classes.index', $account));

        $copiedClassType = ClassType::whereBelongsTo($account)
            ->where('slug', 'kopiya-beginner')
            ->firstOrFail();

        $this->assertSame('Копія Beginner', $copiedClassType->name);
        $this->assertSame($activityDirection->id, $copiedClassType->activity_direction_id);
        $this->assertSame('#123ABC', $copiedClassType->color);
        $this->assertSame(75, $copiedClassType->default_duration_minutes);
        $this->assertSame(60, $copiedClassType->booking_cutoff_minutes);
        $this->assertSame(8, $copiedClassType->default_capacity);
        $this->assertFalse($copiedClassType->is_active);
    }
}
