<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Database\Seeders\ClassPassPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClassPassPlanTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_class_pass_plan(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'name' => 'START ранок',
                'slug' => 'start-morning',
                'available_until_time' => '12:00',
            ]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $classPassPlan = ClassPassPlan::whereBelongsTo($account)->where('slug', 'start-morning')->first();

        $this->assertNotNull($classPassPlan);
        $this->assertModelExists($classPassPlan);
        $this->assertSame(150000, $classPassPlan->price_cents);
        $this->assertSame(180, $classPassPlan->total_validity_days);
        $this->assertFalse($classPassPlan->allows_any_time);
        $this->assertNull($classPassPlan->any_time_addon_price_cents);
        $this->assertTrue($classPassPlan->classTypes()->where('activity_direction_id', $direction->id)->exists());
        $this->assertTrue($classPassPlan->trainerTypes()->whereKey($direction->account->defaultTrainerType()?->id)->exists());
    }

    public function test_owner_can_create_class_pass_plan_with_decimal_currency_price(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'EUR']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'name' => 'Trial',
                'slug' => 'trial',
                'price' => '9.99',
                'currency' => 'EUR',
            ]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $classPassPlan = ClassPassPlan::whereBelongsTo($account)->where('slug', 'trial')->firstOrFail();

        $this->assertSame(999, $classPassPlan->price_cents);
        $this->assertSame('EUR', $classPassPlan->currency);
    }

    public function test_owner_can_store_any_time_addon_settings(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'name' => 'Morning',
                'slug' => 'morning',
                'available_until_time' => '12:00',
                'allows_any_time' => '1',
                'any_time_addon_price' => '75.50',
            ]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $classPassPlan = ClassPassPlan::whereBelongsTo($account)->where('slug', 'morning')->firstOrFail();

        $this->assertTrue($classPassPlan->allows_any_time);
        $this->assertSame(7550, $classPassPlan->any_time_addon_price_cents);
    }

    public function test_any_time_addon_price_is_required_when_enabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'allows_any_time' => '1',
                'any_time_addon_price' => null,
            ]))
            ->assertSessionHasErrors('any_time_addon_price');
    }

    public function test_any_time_addon_price_is_cleared_when_disabled(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();
        $trainerType = $account->ensureDefaultTrainerType();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Morning',
            'slug' => 'morning',
            'allows_any_time' => true,
            'any_time_addon_price_cents' => 5000,
        ]);
        $classPassPlan->classTypes()->sync([$this->classTypeForDirection($direction)->id]);
        $classPassPlan->trainerTypes()->sync([$trainerType->id]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.class-pass-plans.update', [$account, $classPassPlan]), $this->validPayload($direction, [
                'name' => 'Morning updated',
                'slug' => 'morning-updated',
                'allows_any_time' => '0',
                'any_time_addon_price' => 'invalid stale value',
            ]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $classPassPlan->refresh();

        $this->assertFalse($classPassPlan->allows_any_time);
        $this->assertNull($classPassPlan->any_time_addon_price_cents);
    }

    public function test_money_inputs_reject_more_than_two_decimals(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'price' => '9.999',
            ]))
            ->assertSessionHasErrors('price');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'allows_any_time' => '1',
                'any_time_addon_price' => '1.999',
            ]))
            ->assertSessionHasErrors('any_time_addon_price');
    }

    public function test_owner_can_update_and_delete_class_pass_plan(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();
        $trainerType = $account->ensureDefaultTrainerType();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'START', 'slug' => 'start']);
        $classPassPlan->classTypes()->sync([$this->classTypeForDirection($direction)->id]);
        $classPassPlan->trainerTypes()->sync([$trainerType->id]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.class-pass-plans.update', [$account, $classPassPlan]), $this->validPayload($direction, [
                'name' => 'BASE',
                'slug' => 'base',
                'sessions_count' => 8,
                'total_validity_days' => 365,
            ]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $this->assertSame('BASE', $classPassPlan->fresh()->name);
        $this->assertSame(8, $classPassPlan->fresh()->sessions_count);
        $this->assertSame(365, $classPassPlan->fresh()->total_validity_days);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.class-pass-plans.destroy', [$account, $classPassPlan]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $this->assertModelMissing($classPassPlan);
    }

    public function test_owner_can_view_class_pass_plan_screens(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create(['name' => 'Pole Dance']);
        $trainerType = $account->ensureDefaultTrainerType();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'START']);
        $classPassPlan->classTypes()->sync([$this->classTypeForDirection($direction)->id]);
        $classPassPlan->trainerTypes()->sync([$trainerType->id]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertOk()
            ->assertSee('START')
            ->assertSee('Pole Dance')
            ->assertSee($trainerType->name)
            ->assertSee(__('app.validity_after_first_class_short'))
            ->assertSee(__('app.total_validity_short'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.create', $account))
            ->assertOk()
            ->assertSee(__('app.select_all'))
            ->assertSee(__('app.validity_days_after_first_class'))
            ->assertSee(__('app.total_validity_days'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.edit', [$account, $classPassPlan]))
            ->assertOk()
            ->assertSee('START')
            ->assertSee(__('app.validity_days_after_first_class'))
            ->assertSee(__('app.total_validity_days'));
    }

    public function test_class_pass_plan_index_tabs_filter_by_schedule_kind(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);

        $groupType = ClassType::factory()->for($account)->create(['name' => 'Group Pole', 'schedule_kind' => 'group_class']);
        $privateType = ClassType::factory()->for($account)->create(['name' => 'Private Pole', 'schedule_kind' => 'private_lesson']);
        $rentalType = ClassType::factory()->for($account)->create(['name' => 'Rental 60', 'schedule_kind' => 'room_rental']);

        $groupPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Group pass', 'schedule_kind' => 'group_class']);
        $privatePlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Private pass', 'schedule_kind' => 'private_lesson']);
        $rentalPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'Rental pass', 'schedule_kind' => 'room_rental']);

        $groupPlan->classTypes()->sync([$groupType->id]);
        $privatePlan->classTypes()->sync([$privateType->id]);
        $rentalPlan->classTypes()->sync([$rentalType->id]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertOk()
            ->assertSee(__('app.group_classes'))
            ->assertSee(__('app.private_lessons'))
            ->assertSee(__('app.room_rentals'))
            ->assertSee('Group pass')
            ->assertDontSee('Private pass')
            ->assertDontSee('Rental pass');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'private_lesson']))
            ->assertOk()
            ->assertSee('Private pass')
            ->assertDontSee('Group pass')
            ->assertDontSee('Rental pass');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'room_rental']))
            ->assertOk()
            ->assertSee('Rental pass')
            ->assertDontSee('Group pass')
            ->assertDontSee('Private pass');
    }

    public function test_group_class_pass_plan_can_use_multiple_matching_class_types(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();
        $firstClassType = $this->classTypeForDirection($direction);
        $secondClassType = ClassType::factory()->for($account)->for($direction, 'activityDirection')->create([
            'name' => 'Stretching',
            'slug' => 'stretching',
            'schedule_kind' => 'group_class',
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'class_type_ids' => [$firstClassType->id, $secondClassType->id],
            ]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $classPassPlan = ClassPassPlan::whereBelongsTo($account)->where('slug', 'start')->firstOrFail();

        $this->assertSame('group_class', $classPassPlan->schedule_kind->value);
        $this->assertEqualsCanonicalizing([$firstClassType->id, $secondClassType->id], $classPassPlan->classTypes()->pluck('class_types.id')->all());
    }

    public function test_private_and_rental_class_pass_plans_require_one_matching_class_type(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();
        $privateTypes = ClassType::factory()->for($account)->count(2)->create(['schedule_kind' => 'private_lesson']);
        $rentalTypes = ClassType::factory()->for($account)->count(2)->create(['schedule_kind' => 'room_rental']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'schedule_kind' => 'private_lesson',
                'class_type_ids' => $privateTypes->modelKeys(),
            ]))
            ->assertSessionHasErrors('class_type_ids');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'schedule_kind' => 'room_rental',
                'class_type_ids' => $rentalTypes->modelKeys(),
            ]))
            ->assertSessionHasErrors('class_type_ids');
    }

    public function test_class_pass_plan_class_types_must_match_selected_schedule_kind(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();
        $groupClassType = $this->classTypeForDirection($direction);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'schedule_kind' => 'private_lesson',
                'class_type_ids' => [$groupClassType->id],
            ]))
            ->assertSessionHasErrors('class_type_ids');
    }

    public function test_owner_can_copy_class_pass_plan_with_relationships(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $direction = ActivityDirection::factory()->for($account)->create(['name' => 'Pole Dance']);
        $classType = ClassType::factory()
            ->for($account)
            ->for($direction, 'activityDirection')
            ->create(['name' => 'Pole Beginner']);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'START',
            'slug' => 'start',
            'description' => 'Original plan.',
            'price_cents' => 150000,
            'currency' => 'UAH',
            'sessions_count' => 4,
            'validity_days' => 30,
            'total_validity_days' => 240,
            'available_from_time' => '09:00',
            'available_until_time' => '12:00',
            'allows_any_time' => true,
            'any_time_addon_price_cents' => 5000,
            'is_trial' => true,
            'is_active' => false,
            'sort_order' => 15,
        ]);
        $classPassPlan->activityDirections()->sync([$direction->id]);
        $classPassPlan->classTypes()->sync([$classType->id]);
        $classPassPlan->trainerTypes()->sync([$trainerType->id]);
        $classPassPlan->rooms()->sync([$room->id]);

        $this->actingAs($owner)
            ->withSession(['locale' => 'en'])
            ->post(route('dashboard.accounts.class-pass-plans.copy', [$account, $classPassPlan]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => 'group_class']));

        $copy = ClassPassPlan::whereBelongsTo($account)
            ->where('slug', 'copy-start')
            ->firstOrFail();

        $this->assertSame('COPY START', $copy->name);
        $this->assertSame('Original plan.', $copy->description);
        $this->assertSame(150000, $copy->price_cents);
        $this->assertSame('UAH', $copy->currency);
        $this->assertSame(4, $copy->sessions_count);
        $this->assertSame(30, $copy->validity_days);
        $this->assertSame(240, $copy->total_validity_days);
        $this->assertSame('09:00:00', $copy->available_from_time);
        $this->assertSame('12:00:00', $copy->available_until_time);
        $this->assertTrue($copy->allows_any_time);
        $this->assertSame(5000, $copy->any_time_addon_price_cents);
        $this->assertSame('group_class', $copy->schedule_kind->value);
        $this->assertTrue($copy->is_trial);
        $this->assertFalse($copy->is_active);
        $this->assertSame(15, $copy->sort_order);
        $this->assertTrue($copy->activityDirections()->whereKey($direction->getKey())->exists());
        $this->assertTrue($copy->classTypes()->whereKey($classType->getKey())->exists());
        $this->assertTrue($copy->trainerTypes()->whereKey($trainerType->getKey())->exists());
        $this->assertTrue($copy->rooms()->whereKey($room->getKey())->exists());
    }

    public function test_platform_admin_cannot_manage_studio_class_pass_plans(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create();

        $this->actingAs($platformAdmin)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertForbidden();
    }

    public function test_non_owner_studio_member_cannot_manage_class_pass_plans(): void
    {
        $manager = User::factory()->create();
        $account = Account::factory()->create();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create();

        $account->users()->attach($manager->id, [
            'role' => AccountRole::Manager->value,
            'permissions' => null,
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertForbidden();

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.class-pass-plans.copy', [$account, $classPassPlan]))
            ->assertForbidden();
    }

    public function test_class_pass_plan_cannot_use_class_type_from_another_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherDirection = ActivityDirection::factory()->for($otherAccount)->create();
        $otherClassType = ClassType::factory()->for($otherAccount)->for($otherDirection, 'activityDirection')->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($otherDirection, [
                'class_type_ids' => [$otherClassType->id],
            ]))
            ->assertSessionHasErrors('class_type_ids.0');
    }

    public function test_class_pass_plan_time_window_must_end_after_start(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'available_from_time' => '12:00',
                'available_until_time' => '12:00',
            ]))
            ->assertSessionHasErrors('available_until_time');
    }

    public function test_total_validity_cannot_be_shorter_than_validity_after_first_class(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($direction, [
                'validity_days' => 60,
                'total_validity_days' => 30,
            ]))
            ->assertSessionHasErrors('total_validity_days');
    }

    public function test_class_pass_plan_availability_checks_direction_and_start_time(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole Dance']);
        $stretchingDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Stretching']);
        $poleType = ClassType::factory()->for($account)->for($poleDirection, 'activityDirection')->create();
        $stretchingType = ClassType::factory()->for($account)->for($stretchingDirection, 'activityDirection')->create();
        $trainerType = TrainerType::factory()->for($account)->default()->create(['name' => 'Trainer']);
        $topTrainerType = TrainerType::factory()->for($account)->create(['name' => 'TOP-trainer']);
        $trainer = Trainer::factory()->for($account)->create(['trainer_type_id' => $trainerType->id]);
        $topTrainer = Trainer::factory()->for($account)->create(['trainer_type_id' => $topTrainerType->id]);

        $fullDay = ClassPassPlan::factory()->for($account)->create([
            'available_from_time' => null,
            'available_until_time' => null,
            'is_active' => true,
        ]);
        $fullDay->classTypes()->sync([$poleType->id]);
        $fullDay->trainerTypes()->sync([$trainerType->id]);

        $morning = ClassPassPlan::factory()->for($account)->create([
            'available_from_time' => null,
            'available_until_time' => '12:00',
            'is_active' => true,
        ]);
        $morning->classTypes()->sync([$poleType->id]);
        $morning->trainerTypes()->sync([$trainerType->id]);

        $this->assertTrue($fullDay->isAvailableFor($this->scheduledClass($account, $location, $room, $poleType, $trainer, '2026-06-18 16:00:00')));
        $this->assertTrue($morning->isAvailableFor($this->scheduledClass($account, $location, $room, $poleType, $trainer, '2026-06-18 09:00:00')));
        $this->assertTrue($morning->isAvailableFor($this->scheduledClass($account, $location, $room, $poleType, $trainer, '2026-06-18 11:00:00')));
        $this->assertFalse($morning->isAvailableFor($this->scheduledClass($account, $location, $room, $poleType, $trainer, '2026-06-18 12:00:00')));
        $this->assertFalse($morning->isAvailableFor($this->scheduledClass($account, $location, $room, $poleType, $trainer, '2026-06-18 16:00:00')));
        $this->assertFalse($fullDay->isAvailableFor($this->scheduledClass($account, $location, $room, $stretchingType, $trainer, '2026-06-18 09:00:00')));
        $this->assertFalse($fullDay->isAvailableFor($this->scheduledClass($account, $location, $room, $poleType, $topTrainer, '2026-06-18 09:00:00')));
    }

    public function test_demo_class_pass_plan_seeder_is_idempotent(): void
    {
        $account = Account::firstOrCreate(
            ['slug' => 'charmpole'],
            [
                'name' => 'Charmpole',
                'status' => 'active',
                'default_language' => 'uk',
                'default_currency' => 'UAH',
            ],
        );

        $this->seed(ClassPassPlanSeeder::class);
        $this->seed(ClassPassPlanSeeder::class);

        $demoSlugs = [
            'full-day-start',
            'full-day-amateur',
            'full-day-base',
            'full-day-semi-pro',
            'full-day-pro',
            'morning-start',
            'morning-amateur',
            'morning-base',
            'morning-semi-pro',
            'morning-pro',
            'trial-class',
            'private-top-60',
            'private-top-90',
            'private-standard-60',
            'private-standard-90',
            'big-hall-rental-60',
            'big-hall-rental-90',
            'big-hall-rental-120',
            'small-hall-rental-60',
            'small-hall-rental-90',
            'small-hall-rental-120',
        ];

        $query = ClassPassPlan::whereBelongsTo($account)->whereIn('slug', $demoSlugs);

        $this->assertSame(21, (clone $query)->count());
        $this->assertSame(21, (clone $query)->distinct('slug')->count('slug'));
        $this->assertSame(11, (clone $query)->where('schedule_kind', 'group_class')->count());
        $this->assertSame(4, (clone $query)->where('schedule_kind', 'private_lesson')->count());
        $this->assertSame(6, (clone $query)->where('schedule_kind', 'room_rental')->count());
        $this->assertSame(21, (clone $query)->where('total_validity_days', 180)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(ActivityDirection $activityDirection, array $overrides = []): array
    {
        $trainerType = $activityDirection->account->ensureDefaultTrainerType();
        $classType = $this->classTypeForDirection($activityDirection);

        return [
            'name' => 'START',
            'slug' => 'start',
            'schedule_kind' => 'group_class',
            'description' => null,
            'price' => '1500',
            'currency' => 'UAH',
            'sessions_count' => 4,
            'validity_days' => 30,
            'total_validity_days' => 180,
            'available_from_time' => null,
            'available_until_time' => null,
            'allows_any_time' => '0',
            'any_time_addon_price' => null,
            'class_type_ids' => [$classType->id],
            'trainer_type_ids' => [$trainerType->id],
            'room_ids' => [],
            'is_trial' => '0',
            'is_active' => '1',
            'sort_order' => 10,
            ...$overrides,
        ];
    }

    private function classTypeForDirection(ActivityDirection $activityDirection): ClassType
    {
        return ClassType::whereBelongsTo($activityDirection->account)
            ->whereBelongsTo($activityDirection, 'activityDirection')
            ->first()
            ?? ClassType::factory()
                ->for($activityDirection->account)
                ->for($activityDirection, 'activityDirection')
                ->create(['name' => $activityDirection->name, 'slug' => $activityDirection->slug, 'schedule_kind' => 'group_class']);
    }

    private function scheduledClass(
        Account $account,
        Location $location,
        Room $room,
        ClassType $classType,
        Trainer $trainer,
        string $startsAt,
    ): ScheduledClass {
        return ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => Carbon::parse($startsAt),
                'ends_at' => Carbon::parse($startsAt)->addHour(),
            ]);
    }
}
