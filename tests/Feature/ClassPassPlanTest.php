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
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', $account));

        $classPassPlan = ClassPassPlan::whereBelongsTo($account)->where('slug', 'start-morning')->first();

        $this->assertNotNull($classPassPlan);
        $this->assertModelExists($classPassPlan);
        $this->assertTrue($classPassPlan->activityDirections()->whereKey($direction)->exists());
        $this->assertTrue($classPassPlan->trainerTypes()->whereKey($direction->account->defaultTrainerType()?->id)->exists());
    }

    public function test_owner_can_update_and_delete_class_pass_plan(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $direction = ActivityDirection::factory()->for($account)->create();
        $trainerType = $account->ensureDefaultTrainerType();
        $classPassPlan = ClassPassPlan::factory()->for($account)->create(['name' => 'START', 'slug' => 'start']);
        $classPassPlan->activityDirections()->sync([$direction->id]);
        $classPassPlan->trainerTypes()->sync([$trainerType->id]);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.class-pass-plans.update', [$account, $classPassPlan]), $this->validPayload($direction, [
                'name' => 'BASE',
                'slug' => 'base',
                'sessions_count' => 8,
            ]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', $account));

        $this->assertSame('BASE', $classPassPlan->fresh()->name);
        $this->assertSame(8, $classPassPlan->fresh()->sessions_count);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.class-pass-plans.destroy', [$account, $classPassPlan]))
            ->assertRedirect(route('dashboard.accounts.class-pass-plans.index', $account));

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
        $classPassPlan->activityDirections()->sync([$direction->id]);
        $classPassPlan->trainerTypes()->sync([$trainerType->id]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertOk()
            ->assertSee('START')
            ->assertSee('Pole Dance')
            ->assertSee($trainerType->name);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.create', $account))
            ->assertOk()
            ->assertSee(__('app.select_all'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.edit', [$account, $classPassPlan]))
            ->assertOk()
            ->assertSee('START');
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

        $account->users()->attach($manager->id, [
            'role' => AccountRole::Manager->value,
            'permissions' => null,
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertForbidden();
    }

    public function test_class_pass_plan_cannot_use_activity_direction_from_another_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherDirection = ActivityDirection::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.class-pass-plans.store', $account), $this->validPayload($otherDirection))
            ->assertSessionHasErrors('activity_direction_ids.0');
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
        $fullDay->activityDirections()->sync([$poleDirection->id]);
        $fullDay->trainerTypes()->sync([$trainerType->id]);

        $morning = ClassPassPlan::factory()->for($account)->create([
            'available_from_time' => null,
            'available_until_time' => '12:00',
            'is_active' => true,
        ]);
        $morning->activityDirections()->sync([$poleDirection->id]);
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
        ];

        $query = ClassPassPlan::whereBelongsTo($account)->whereIn('slug', $demoSlugs);

        $this->assertSame(10, (clone $query)->count());
        $this->assertSame(10, (clone $query)->distinct('slug')->count('slug'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(ActivityDirection $activityDirection, array $overrides = []): array
    {
        $trainerType = $activityDirection->account->ensureDefaultTrainerType();

        return [
            'name' => 'START',
            'slug' => 'start',
            'description' => null,
            'price_cents' => 150000,
            'currency' => 'UAH',
            'sessions_count' => 4,
            'validity_days' => 30,
            'available_from_time' => null,
            'available_until_time' => null,
            'activity_direction_ids' => [$activityDirection->id],
            'trainer_type_ids' => [$trainerType->id],
            'is_active' => '1',
            'sort_order' => 10,
            ...$overrides,
        ];
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
