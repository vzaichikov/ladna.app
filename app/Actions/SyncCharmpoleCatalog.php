<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Room;
use App\Models\TrainerType;
use App\Support\CharmpoleDemoCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SyncCharmpoleCatalog
{
    /**
     * @return array<string, mixed>
     */
    public function execute(string $accountSlug = 'charmpole', bool $shouldExecute = false): array
    {
        $account = Account::where('slug', $accountSlug)->first();

        if (! $account) {
            throw new RuntimeException("Account [{$accountSlug}] was not found.");
        }

        $target = $this->targetData();
        $this->resolveTrainerTypes($account, $target['required_trainer_type_keys']);
        $this->resolveRooms($account, $target['required_room_slugs']);

        if (! $shouldExecute) {
            return [
                ...$this->summary($account, $target),
                'mode' => 'dry-run',
                'backup_path' => null,
            ];
        }

        $backupPath = $this->writeBackup($account);

        DB::transaction(function () use ($account, $target): void {
            $trainerTypes = $this->resolveTrainerTypes($account, $target['required_trainer_type_keys']);
            $rooms = $this->resolveRooms($account, $target['required_room_slugs']);

            $this->syncDirections($account, $target['directions']);
            $directions = $account->activityDirections()->get()->keyBy('slug');

            $this->syncClassTypes($account, $target['class_types'], $directions);
            $classTypes = $account->classTypes()->get()->keyBy('slug');

            $this->syncClassPassPlans($account, $target['plans'], $classTypes, $trainerTypes, $rooms);
            $this->removeObsoleteClassPassPlans($account, $target['plan_slugs']);
            $this->removeObsoleteClassTypes($account, $target['class_type_slugs']);
            $this->removeObsoleteDirections($account, $target['direction_slugs']);
        });

        return [
            ...$this->summary($account->fresh(), $target),
            'mode' => 'execute',
            'backup_path' => $backupPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetData(): array
    {
        $plans = CharmpoleDemoCatalog::classPassPlans();
        $trainerTypeKeys = collect($plans)
            ->flatMap(fn (array $plan): array => $plan['trainer_type_keys'])
            ->unique()
            ->values()
            ->all();
        $roomSlugs = collect($plans)
            ->flatMap(fn (array $plan): array => $plan['room_slugs'])
            ->unique()
            ->values()
            ->all();

        return [
            'directions' => CharmpoleDemoCatalog::directions(),
            'class_types' => CharmpoleDemoCatalog::classTypes(),
            'plans' => $plans,
            'direction_slugs' => array_keys(CharmpoleDemoCatalog::directions()),
            'class_type_slugs' => array_keys(CharmpoleDemoCatalog::classTypes()),
            'plan_slugs' => array_keys($plans),
            'required_trainer_type_keys' => $trainerTypeKeys,
            'required_room_slugs' => $roomSlugs,
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function summary(Account $account, array $target): array
    {
        $directionSlugs = $account->activityDirections()->pluck('slug')->all();
        $classTypeSlugs = $account->classTypes()->pluck('slug')->all();
        $planSlugs = $account->classPassPlans()->pluck('slug')->all();

        $obsoletePlanCount = $account->classPassPlans()
            ->whereNotIn('slug', $target['plan_slugs'])
            ->count();
        $obsoleteClassTypeCount = $account->classTypes()
            ->whereNotIn('slug', $target['class_type_slugs'])
            ->count();
        $obsoleteDirectionCount = $account->activityDirections()
            ->whereNotIn('slug', $target['direction_slugs'])
            ->count();
        $protectedPlanCount = $account->classPassPlans()
            ->whereNotIn('slug', $target['plan_slugs'])
            ->whereHas('customerClassPasses')
            ->count();
        $protectedClassTypeCount = $account->classTypes()
            ->whereNotIn('slug', $target['class_type_slugs'])
            ->where(function (Builder $query): void {
                $query->whereHas('scheduleSeries')
                    ->orWhereHas('scheduledClasses');
            })
            ->count();

        return [
            'account_id' => $account->id,
            'account_slug' => $account->slug,
            'target_directions' => count($target['direction_slugs']),
            'target_class_types' => count($target['class_type_slugs']),
            'target_class_pass_plans' => count($target['plan_slugs']),
            'matched_directions' => count(array_intersect($directionSlugs, $target['direction_slugs'])),
            'matched_class_types' => count(array_intersect($classTypeSlugs, $target['class_type_slugs'])),
            'matched_class_pass_plans' => count(array_intersect($planSlugs, $target['plan_slugs'])),
            'obsolete_directions' => $obsoleteDirectionCount,
            'obsolete_class_types' => $obsoleteClassTypeCount,
            'obsolete_class_pass_plans' => $obsoletePlanCount,
            'protected_obsolete_class_types' => $protectedClassTypeCount,
            'protected_obsolete_class_pass_plans' => $protectedPlanCount,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $directions
     */
    private function syncDirections(Account $account, array $directions): void
    {
        foreach ($directions as $slug => $direction) {
            $account->activityDirections()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $direction['name'],
                    'description' => $direction['description'],
                    'color' => $direction['color'],
                    'is_active' => $direction['is_active'],
                ],
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $classTypes
     * @param  Collection<string, ActivityDirection>  $directions
     */
    private function syncClassTypes(Account $account, array $classTypes, Collection $directions): void
    {
        foreach ($classTypes as $slug => $classType) {
            $directionSlug = $classType['direction_slug'];
            $direction = is_string($directionSlug) ? $directions->get($directionSlug) : null;

            if (is_string($directionSlug) && ! $direction) {
                throw new RuntimeException("Direction [{$directionSlug}] was not found for class type [{$slug}].");
            }

            $account->classTypes()->updateOrCreate(
                ['slug' => $slug],
                [
                    'activity_direction_id' => $direction?->id,
                    'name' => $classType['name'],
                    'description' => $classType['description'],
                    'color' => $classType['color'],
                    'schedule_kind' => $classType['schedule_kind'],
                    'default_duration_minutes' => $classType['default_duration_minutes'],
                    'booking_cutoff_minutes' => $classType['booking_cutoff_minutes'],
                    'default_capacity' => $classType['default_capacity'],
                    'is_active' => $classType['is_active'],
                ],
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $plans
     * @param  Collection<string, ClassType>  $classTypes
     * @param  array<string, TrainerType>  $trainerTypes
     * @param  Collection<string, Room>  $rooms
     */
    private function syncClassPassPlans(Account $account, array $plans, Collection $classTypes, array $trainerTypes, Collection $rooms): void
    {
        foreach ($plans as $slug => $plan) {
            $classPassPlan = $account->classPassPlans()->updateOrCreate(
                ['slug' => $slug],
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
            $classPassPlan->classTypes()->sync($this->idsForSlugs($classTypes, $plan['class_type_slugs'], 'class type', $slug));
            $classPassPlan->trainerTypes()->sync($this->idsForKeys($trainerTypes, $plan['trainer_type_keys'], 'trainer type', $slug));
            $classPassPlan->rooms()->sync($this->idsForSlugs($rooms, $plan['room_slugs'], 'room', $slug));
        }
    }

    /**
     * @param  array<string, mixed>  $targetPlanSlugs
     */
    private function removeObsoleteClassPassPlans(Account $account, array $targetPlanSlugs): void
    {
        $account->classPassPlans()
            ->whereNotIn('slug', $targetPlanSlugs)
            ->get()
            ->each(function (ClassPassPlan $classPassPlan): void {
                if ($classPassPlan->customerClassPasses()->exists()) {
                    $classPassPlan->forceFill(['is_active' => false])->save();

                    return;
                }

                $classPassPlan->delete();
            });
    }

    /**
     * @param  array<string, mixed>  $targetClassTypeSlugs
     */
    private function removeObsoleteClassTypes(Account $account, array $targetClassTypeSlugs): void
    {
        $account->classTypes()
            ->whereNotIn('slug', $targetClassTypeSlugs)
            ->get()
            ->each(function (ClassType $classType): void {
                if ($classType->scheduleSeries()->exists() || $classType->scheduledClasses()->exists()) {
                    $classType->forceFill(['is_active' => false])->save();

                    return;
                }

                $classType->delete();
            });
    }

    /**
     * @param  array<string, mixed>  $targetDirectionSlugs
     */
    private function removeObsoleteDirections(Account $account, array $targetDirectionSlugs): void
    {
        $account->activityDirections()
            ->whereNotIn('slug', $targetDirectionSlugs)
            ->get()
            ->each(function (ActivityDirection $direction): void {
                if ($direction->classTypes()->exists()) {
                    $direction->forceFill(['is_active' => false])->save();

                    return;
                }

                $direction->delete();
            });
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, TrainerType>
     */
    private function resolveTrainerTypes(Account $account, array $keys): array
    {
        $trainerTypes = [];

        if (in_array('trainer', $keys, true)) {
            $trainerTypes['trainer'] = $account->trainerTypes()
                ->where('is_default', true)
                ->ordered()
                ->first();
        }

        if (in_array('top', $keys, true)) {
            $trainerTypes['top'] = $account->trainerTypes()
                ->where('is_default', false)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->first();
        }

        foreach ($keys as $key) {
            if (! isset($trainerTypes[$key])) {
                throw new RuntimeException("Required trainer type key [{$key}] was not found for account [{$account->slug}].");
            }
        }

        return $trainerTypes;
    }

    /**
     * @param  array<int, string>  $slugs
     * @return Collection<string, Room>
     */
    private function resolveRooms(Account $account, array $slugs): Collection
    {
        $rooms = $account->rooms()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $missing = collect($slugs)
            ->reject(fn (string $slug): bool => $rooms->has($slug))
            ->values();

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Required room slugs were not found for account ['.$account->slug.']: '.$missing->implode(', '));
        }

        return $rooms;
    }

    /**
     * @param  Collection<string, mixed>  $models
     * @param  array<int, string>  $slugs
     * @return array<int, int>
     */
    private function idsForSlugs(Collection $models, array $slugs, string $label, string $planSlug): array
    {
        return collect($slugs)
            ->map(function (string $slug) use ($models, $label, $planSlug): int {
                $model = $models->get($slug);

                if (! $model) {
                    throw new RuntimeException("Required {$label} [{$slug}] was not found for plan [{$planSlug}].");
                }

                return $model->id;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $models
     * @param  array<int, string>  $keys
     * @return array<int, int>
     */
    private function idsForKeys(array $models, array $keys, string $label, string $planSlug): array
    {
        return collect($keys)
            ->map(function (string $key) use ($models, $label, $planSlug): int {
                $model = $models[$key] ?? null;

                if (! $model) {
                    throw new RuntimeException("Required {$label} [{$key}] was not found for plan [{$planSlug}].");
                }

                return $model->id;
            })
            ->all();
    }

    private function writeBackup(Account $account): string
    {
        $timestamp = now()->format('Ymd-His');
        $path = "charmpole-catalog-backups/{$timestamp}-account-{$account->id}.json";

        Storage::disk('local')->put($path, json_encode($this->backupPayload($account), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function backupPayload(Account $account): array
    {
        return [
            'created_at' => now()->toIso8601String(),
            'account' => [
                'id' => $account->id,
                'slug' => $account->slug,
                'name' => $account->name,
            ],
            'directions' => $account->activityDirections()
                ->orderBy('id')
                ->get(['id', 'name', 'slug', 'description', 'color', 'is_active'])
                ->toArray(),
            'class_types' => $account->classTypes()
                ->with('activityDirection:id,slug')
                ->orderBy('id')
                ->get(['id', 'activity_direction_id', 'name', 'slug', 'description', 'color', 'schedule_kind', 'default_duration_minutes', 'booking_cutoff_minutes', 'default_capacity', 'is_active'])
                ->toArray(),
            'class_pass_plans' => $account->classPassPlans()
                ->with([
                    'classTypes:id,slug',
                    'rooms:id,slug',
                    'trainerTypes:id,name,is_default,sort_order',
                ])
                ->orderBy('id')
                ->get()
                ->toArray(),
            'rooms' => $account->rooms()
                ->orderBy('id')
                ->get(['id', 'name', 'slug', 'capacity', 'is_active'])
                ->toArray(),
            'trainer_types' => $account->trainerTypes()
                ->orderBy('id')
                ->get(['id', 'name', 'icon', 'color', 'is_default', 'sort_order'])
                ->toArray(),
        ];
    }
}
