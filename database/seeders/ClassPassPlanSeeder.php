<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\TrainerType;
use App\Support\CharmpoleDemoCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class ClassPassPlanSeeder extends Seeder
{
    public function run(): void
    {
        $account = Account::where('slug', 'charmpole')->first();

        if (! $account) {
            $this->command?->warn('Class pass demo data skipped: charmpole account was not found.');

            return;
        }

        $location = $account->locations()->firstOrCreate(
            ['slug' => 'charmpole'],
            [
                'name' => 'Charmpole',
                'address' => 'Київ, проспект Берестейський (Перемоги), 56',
                'timezone' => 'Europe/Kyiv',
                'is_active' => true,
            ],
        );

        $directions = $this->directions($account);
        $rooms = $this->rooms($account, $location);
        $classTypes = $this->classTypes($account, $directions);
        $trainerTypes = $this->trainerTypes($account);

        foreach (CharmpoleDemoCatalog::classPassPlans() as $slug => $plan) {
            $classPassPlan = ClassPassPlan::updateOrCreate(
                [
                    'account_id' => $account->id,
                    'slug' => $slug,
                ],
                Arr::only($plan, [
                    'name',
                    'description',
                    'schedule_kind',
                    'price_cents',
                    'sessions_count',
                    'validity_days',
                    'total_validity_days',
                    'available_from_time',
                    'available_until_time',
                    'allows_any_time',
                    'any_time_addon_price_cents',
                    'is_trial',
                    'is_active',
                    'sort_order',
                ]) + [
                    'currency' => $account->default_currency,
                ],
            );

            $classPassPlan->activityDirections()->sync([]);
            $classPassPlan->classTypes()->sync(collect($plan['class_type_slugs'])->map(fn (string $classTypeSlug): int => $classTypes[$classTypeSlug]->id)->all());
            $classPassPlan->trainerTypes()->sync(collect($plan['trainer_type_keys'])->map(fn (string $trainerTypeKey): int => $trainerTypes[$trainerTypeKey]->id)->all());
            $classPassPlan->rooms()->sync(collect($plan['room_slugs'])->map(fn (string $roomSlug): int => $rooms[$roomSlug]->id)->all());
        }
    }

    /**
     * @return array<string, ActivityDirection>
     */
    private function directions(Account $account): array
    {
        return collect(CharmpoleDemoCatalog::directions())
            ->mapWithKeys(fn (array $direction, string $slug): array => [$slug => $account->activityDirections()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $direction['name'],
                    'description' => $direction['description'],
                    'color' => $direction['color'],
                    'is_active' => $direction['is_active'],
                ],
            )])
            ->all();
    }

    /**
     * @return array<string, Room>
     */
    private function rooms(Account $account, Location $location): array
    {
        return collect(CharmpoleDemoCatalog::rooms())
            ->mapWithKeys(fn (array $room, string $slug): array => [$slug => $account->rooms()->updateOrCreate(
                [
                    'location_id' => $location->id,
                    'slug' => $slug,
                ],
                [
                    'name' => $room['name'],
                    'capacity' => $room['capacity'],
                    'is_active' => $room['is_active'],
                ],
            )])
            ->all();
    }

    /**
     * @param  array<string, ActivityDirection>  $directions
     * @return array<string, ClassType>
     */
    private function classTypes(Account $account, array $directions): array
    {
        return collect(CharmpoleDemoCatalog::classTypes())
            ->mapWithKeys(function (array $classType, string $slug) use ($account, $directions): array {
                $directionSlug = $classType['direction_slug'];

                return [$slug => $account->classTypes()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'activity_direction_id' => is_string($directionSlug) ? $directions[$directionSlug]->id : null,
                        'name' => $classType['name'],
                        'description' => $classType['description'],
                        'color' => $classType['color'],
                        'schedule_kind' => $classType['schedule_kind'],
                        'default_duration_minutes' => $classType['default_duration_minutes'],
                        'booking_cutoff_minutes' => $classType['booking_cutoff_minutes'],
                        'default_capacity' => $classType['default_capacity'],
                        'is_active' => $classType['is_active'],
                    ],
                )];
            })
            ->all();
    }

    /**
     * @return array<string, TrainerType>
     */
    private function trainerTypes(Account $account): array
    {
        return collect(CharmpoleDemoCatalog::trainerTypes())
            ->mapWithKeys(fn (array $trainerType, string $key): array => [$key => $account->trainerTypes()->updateOrCreate(
                ['name' => $trainerType['name']],
                [
                    'icon' => $trainerType['icon'],
                    'color' => $trainerType['color'],
                    'is_default' => $trainerType['is_default'],
                    'sort_order' => $trainerType['sort_order'],
                ],
            )])
            ->all();
    }
}
