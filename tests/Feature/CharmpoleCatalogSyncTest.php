<?php

namespace Tests\Feature;

use App\Actions\SyncCharmpoleCatalog;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Support\CharmpoleDemoCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CharmpoleCatalogSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_charmpole_catalog_sync_dry_run_does_not_change_data(): void
    {
        $context = $this->catalogSyncContext('charmpole-sync-dry-run');

        $summary = app(SyncCharmpoleCatalog::class)->execute($context['account']->slug);

        $this->assertSame('dry-run', $summary['mode']);
        $this->assertNull($summary['backup_path']);
        $this->assertSame(13, $summary['target_class_types']);
        $this->assertSame(21, $summary['target_class_pass_plans']);
        $this->assertFalse($context['account']->classTypes()->where('slug', 'pole-dance')->exists());
        $this->assertTrue($context['account']->classTypes()->where('slug', 'legacy-private')->exists());
        $this->assertTrue($context['account']->classPassPlans()->where('slug', 'legacy-plan')->exists());
    }

    public function test_charmpole_catalog_sync_updates_catalog_without_touching_protected_studio_data(): void
    {
        Storage::fake('local');

        $context = $this->catalogSyncContext('charmpole-sync-execute');
        $account = $context['account'];
        $topTrainerTypeId = $context['topTrainerType']->id;
        $trainerSnapshot = $context['trainer']->only(['id', 'trainer_type_id', 'name', 'slug', 'email']);

        $summary = app(SyncCharmpoleCatalog::class)->execute($account->slug, true);

        $this->assertSame('execute', $summary['mode']);
        $this->assertIsString($summary['backup_path']);
        Storage::disk('local')->assertExists($summary['backup_path']);

        $account->refresh();
        $this->assertSame(['group_class'], $account->enabled_schedule_kinds);
        $this->assertSame(['group_class' => '#111111'], $account->schedule_kind_colors);
        $this->assertSame('#123456', $account->brand_color);
        $this->assertSame('brand/custom.svg', $account->logo_path);

        $this->assertSame(5, $account->activityDirections()->whereIn('slug', array_keys(CharmpoleDemoCatalog::directions()))->count());
        $this->assertSame('#ffffff', $account->activityDirections()->where('slug', 'kids')->firstOrFail()->color);
        $this->assertSame(13, $account->classTypes()->whereIn('slug', array_keys(CharmpoleDemoCatalog::classTypes()))->count());
        $this->assertSame(21, $account->classPassPlans()->whereIn('slug', array_keys(CharmpoleDemoCatalog::classPassPlans()))->count());
        $this->assertSame(11, $account->classPassPlans()->whereIn('slug', array_keys(CharmpoleDemoCatalog::classPassPlans()))->where('schedule_kind', ScheduleKind::GroupClass->value)->count());
        $this->assertSame(4, $account->classPassPlans()->whereIn('slug', array_keys(CharmpoleDemoCatalog::classPassPlans()))->where('schedule_kind', ScheduleKind::PrivateLesson->value)->count());
        $this->assertSame(6, $account->classPassPlans()->whereIn('slug', array_keys(CharmpoleDemoCatalog::classPassPlans()))->where('schedule_kind', ScheduleKind::RoomRental->value)->count());
        $this->assertSame(21, $account->classPassPlans()->whereIn('slug', array_keys(CharmpoleDemoCatalog::classPassPlans()))->where('total_validity_days', 180)->count());

        $this->assertFalse($account->classTypes()->where('slug', 'legacy-private')->exists());
        $this->assertFalse($account->classPassPlans()->where('slug', 'legacy-plan')->exists());
        $this->assertFalse($account->classPassPlans()->where('slug', 'legacy-protected-plan')->firstOrFail()->is_active);
        $this->assertFalse($account->classTypes()->where('slug', 'legacy-series-type')->firstOrFail()->is_active);

        $this->assertSame('Великий зал existing', $context['bigHall']->fresh()->name);
        $this->assertSame('TOP-тренер', $context['topTrainerType']->fresh()->name);
        $this->assertSame($trainerSnapshot, $context['trainer']->fresh()->only(['id', 'trainer_type_id', 'name', 'slug', 'email']));

        $this->assertTrue($account->classPassPlans()
            ->where('slug', 'private-top-60')
            ->firstOrFail()
            ->trainerTypes()
            ->whereKey($topTrainerTypeId)
            ->exists());

        $this->assertTrue($account->classPassPlans()
            ->where('slug', 'big-hall-rental-60')
            ->firstOrFail()
            ->rooms()
            ->where('slug', 'big-hall')
            ->exists());

        $this->assertSame(98, $this->classPassClassTypePivotCount($account));
        $this->assertSame(26, $this->classPassTrainerTypePivotCount($account));
        $this->assertSame(6, $this->classPassRoomPivotCount($account));
    }

    public function test_charmpole_catalog_sync_command_runs_dry_run_by_default(): void
    {
        $context = $this->catalogSyncContext('charmpole-sync-command');

        $this->artisan('ladna:sync-charmpole-catalog', ['--account' => $context['account']->slug])
            ->assertSuccessful();

        $this->assertFalse($context['account']->classTypes()->where('slug', 'pole-dance')->exists());
    }

    /**
     * @return array{
     *     account: Account,
     *     bigHall: Room,
     *     topTrainerType: TrainerType,
     *     trainer: Trainer
     * }
     */
    private function catalogSyncContext(string $slug): array
    {
        $account = Account::factory()->create([
            'name' => 'Charmpole Sync Test',
            'slug' => $slug,
            'brand_color' => '#123456',
            'logo_path' => 'brand/custom.svg',
            'enabled_schedule_kinds' => [ScheduleKind::GroupClass->value],
            'schedule_kind_colors' => [ScheduleKind::GroupClass->value => '#111111'],
            'default_currency' => 'UAH',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'charmpole']);
        $bigHall = Room::factory()->for($account)->for($location)->create(['name' => 'Великий зал existing', 'slug' => 'big-hall']);
        Room::factory()->for($account)->for($location)->create(['name' => 'Малий зал existing', 'slug' => 'small-hall']);
        $trainerType = TrainerType::factory()->for($account)->default()->create(['name' => 'Тренер']);
        $topTrainerType = TrainerType::factory()->for($account)->create([
            'name' => 'TOP-тренер',
            'icon' => 'crown',
            'is_default' => false,
            'sort_order' => 20,
        ]);
        $trainer = Trainer::factory()->for($account)->create([
            'trainer_type_id' => $topTrainerType->id,
            'name' => 'Protected Trainer',
            'slug' => 'protected-trainer',
            'email' => 'protected@example.com',
        ]);
        $legacyDirection = ActivityDirection::factory()->for($account)->create(['slug' => 'legacy-direction']);
        $legacyPrivateClassType = ClassType::factory()
            ->for($account)
            ->for($legacyDirection, 'activityDirection')
            ->create(['slug' => 'legacy-private', 'schedule_kind' => ScheduleKind::PrivateLesson->value]);
        $legacySeriesClassType = ClassType::factory()
            ->for($account)
            ->for($legacyDirection, 'activityDirection')
            ->create(['slug' => 'legacy-series-type', 'schedule_kind' => ScheduleKind::GroupClass->value]);
        $legacyPlan = ClassPassPlan::factory()->for($account)->create(['slug' => 'legacy-plan', 'schedule_kind' => ScheduleKind::PrivateLesson->value]);
        $legacyPlan->classTypes()->sync([$legacyPrivateClassType->id]);
        $protectedPlan = ClassPassPlan::factory()->for($account)->create(['slug' => 'legacy-protected-plan', 'schedule_kind' => ScheduleKind::PrivateLesson->value]);
        $protectedPlan->classTypes()->sync([$legacyPrivateClassType->id]);
        $customer = Customer::factory()->for($account)->create();
        CustomerClassPass::factory()->for($account)->for($customer)->for($protectedPlan, 'classPassPlan')->create([
            'plan_slug' => $protectedPlan->slug,
            'plan_name' => $protectedPlan->name,
        ]);
        ScheduleSeries::factory()
            ->for($account)
            ->for($location)
            ->for($bigHall, 'room')
            ->for($legacySeriesClassType)
            ->for($trainer)
            ->create();

        return [
            'account' => $account,
            'bigHall' => $bigHall,
            'topTrainerType' => $topTrainerType,
            'trainer' => $trainer,
        ];
    }

    private function classPassClassTypePivotCount(Account $account): int
    {
        return (int) $account->classPassPlans()
            ->withCount('classTypes')
            ->get()
            ->sum('class_types_count');
    }

    private function classPassTrainerTypePivotCount(Account $account): int
    {
        return (int) $account->classPassPlans()
            ->withCount('trainerTypes')
            ->get()
            ->sum('trainer_types_count');
    }

    private function classPassRoomPivotCount(Account $account): int
    {
        return (int) $account->classPassPlans()
            ->withCount('rooms')
            ->get()
            ->sum('rooms_count');
    }
}
