<?php

namespace App\Actions;

use App\Enums\TrainerSubstitutionMode;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\TrainerSubstitution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SyncTrainerSubstitutions
{
    public const MetadataKey = 'trainer_substitution';

    public function syncGeneratedWindow(Account $account): void
    {
        $timezone = $this->timezone($account);
        $from = CarbonImmutable::now($timezone)->startOfDay();
        $until = $from->addWeeks($account->scheduleGenerationWeeks())->endOfDay();

        $this->syncWindow($account, $from, $until);
    }

    /**
     * @param  array<int, int>  $scheduledClassIds
     */
    public function syncAfterSubstitutionChange(Account $account, array $scheduledClassIds = []): void
    {
        $timezone = $this->timezone($account);
        $from = CarbonImmutable::now($timezone)->subDays(2)->startOfDay();
        $until = CarbonImmutable::now($timezone)
            ->startOfDay()
            ->addWeeks($account->scheduleGenerationWeeks())
            ->endOfDay();

        if ($scheduledClassIds !== []) {
            $classRange = $this->classRange($account, $scheduledClassIds, $timezone);

            if ($classRange !== null) {
                [$classFrom, $classUntil] = $classRange;

                if ($classFrom->lessThan($from)) {
                    $from = $classFrom;
                }

                if ($classUntil->greaterThan($until)) {
                    $until = $classUntil;
                }
            }
        }

        $this->syncWindow($account, $from, $until, $scheduledClassIds);
    }

    /**
     * @param  array<int, int>  $scheduledClassIds
     */
    public function syncWindow(Account $account, CarbonImmutable $from, CarbonImmutable $until, array $scheduledClassIds = []): void
    {
        $timezone = $this->timezone($account);
        $substitutions = $this->substitutionsForWindow($account, $from, $until);

        $this->classesForWindow($account, $from, $until, $scheduledClassIds)
            ->each(function (ScheduledClass $scheduledClass) use ($substitutions, $timezone): void {
                $this->syncClass($scheduledClass, $substitutions, $timezone);
            });
    }

    /**
     * @param  Collection<int, TrainerSubstitution>  $substitutions
     */
    private function syncClass(ScheduledClass $scheduledClass, Collection $substitutions, string $timezone): void
    {
        if (data_get($scheduledClass->metadata, ScheduledClass::MANUAL_TRAINER_OVERRIDE_METADATA_KEY)) {
            return;
        }

        $matchingSubstitution = $this->matchingSubstitution($scheduledClass, $substitutions, $timezone);
        $existingMetadata = $this->substitutionMetadata($scheduledClass);
        $metadata = $scheduledClass->metadata ?? [];

        if ($matchingSubstitution) {
            $originalTrainerId = $existingMetadata && (int) ($existingMetadata['id'] ?? 0) === $matchingSubstitution->id
                ? (int) ($existingMetadata['original_trainer_id'] ?? $scheduledClass->trainer_id)
                : $this->originalTrainerId($scheduledClass);

            $metadata[self::MetadataKey] = [
                'id' => $matchingSubstitution->id,
                'mode' => $matchingSubstitution->mode->value,
                'original_trainer_id' => $originalTrainerId,
                'replaced_trainer_id' => $matchingSubstitution->replaced_trainer_id,
                'substitute_trainer_id' => $matchingSubstitution->substitute_trainer_id,
                'replaced_trainer_name' => $matchingSubstitution->replaced_trainer_name,
                'substitute_trainer_name' => $matchingSubstitution->substitute_trainer_name,
            ];

            $scheduledClass->forceFill([
                'trainer_id' => $matchingSubstitution->substitute_trainer_id,
                'metadata' => $metadata,
            ]);
        } elseif ($existingMetadata) {
            unset($metadata[self::MetadataKey]);

            $scheduledClass->forceFill([
                'trainer_id' => $existingMetadata['original_trainer_id'] ?? $scheduledClass->trainer_id,
                'metadata' => $metadata,
            ]);
        }

        if ($scheduledClass->isDirty()) {
            $scheduledClass->save();
        }
    }

    /**
     * @param  Collection<int, TrainerSubstitution>  $substitutions
     */
    private function matchingSubstitution(ScheduledClass $scheduledClass, Collection $substitutions, string $timezone): ?TrainerSubstitution
    {
        $displayDate = $scheduledClass->starts_at->copy()->timezone($timezone)->toDateString();
        $originalTrainerId = $this->originalTrainerId($scheduledClass);

        return $substitutions
            ->first(function (TrainerSubstitution $substitution) use ($scheduledClass, $displayDate, $originalTrainerId): bool {
                if ((int) $substitution->replaced_trainer_id !== $originalTrainerId) {
                    return false;
                }

                if ($substitution->date_from->toDateString() > $displayDate || $substitution->date_to->toDateString() < $displayDate) {
                    return false;
                }

                if ($substitution->mode === TrainerSubstitutionMode::Classes) {
                    return in_array($scheduledClass->id, $this->ids($substitution->scheduled_class_ids), true);
                }

                return (int) $substitution->location_id === (int) $scheduledClass->location_id
                    && (int) $substitution->room_id === (int) $scheduledClass->room_id
                    && in_array((int) $scheduledClass->class_type_id, $this->ids($substitution->class_type_ids), true);
            });
    }

    /**
     * @return Collection<int, TrainerSubstitution>
     */
    private function substitutionsForWindow(Account $account, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return $account->trainerSubstitutions()
            ->whereNotNull('substitute_trainer_id')
            ->whereDate('date_to', '>=', $from->toDateString())
            ->whereDate('date_from', '<=', $until->toDateString())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, int>  $scheduledClassIds
     * @return Collection<int, ScheduledClass>
     */
    private function classesForWindow(Account $account, CarbonImmutable $from, CarbonImmutable $until, array $scheduledClassIds = []): Collection
    {
        $databaseFrom = $from->timezone(config('app.timezone'));
        $databaseUntil = $until->timezone(config('app.timezone'));

        return $account->scheduledClasses()
            ->where(function (Builder $query) use ($databaseFrom, $databaseUntil, $scheduledClassIds): void {
                $query->whereBetween('starts_at', [$databaseFrom, $databaseUntil]);

                if ($scheduledClassIds !== []) {
                    $query->orWhereIn('id', $scheduledClassIds);
                }
            })
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  array<int, int>  $scheduledClassIds
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function classRange(Account $account, array $scheduledClassIds, string $timezone): ?array
    {
        $classes = $account->scheduledClasses()
            ->whereIn('id', $scheduledClassIds)
            ->get(['id', 'starts_at', 'ends_at']);

        if ($classes->isEmpty()) {
            return null;
        }

        $firstClass = $classes->sortBy('starts_at')->first();
        $lastClass = $classes->sortByDesc('ends_at')->first();

        return [
            CarbonImmutable::instance($firstClass->starts_at)->timezone($timezone)->startOfDay(),
            CarbonImmutable::instance($lastClass->ends_at)->timezone($timezone)->endOfDay(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function substitutionMetadata(ScheduledClass $scheduledClass): ?array
    {
        $metadata = $scheduledClass->metadata;

        if (! is_array($metadata) || ! is_array($metadata[self::MetadataKey] ?? null)) {
            return null;
        }

        return $metadata[self::MetadataKey];
    }

    private function originalTrainerId(ScheduledClass $scheduledClass): int
    {
        $existingMetadata = $this->substitutionMetadata($scheduledClass);

        if ($existingMetadata && isset($existingMetadata['original_trainer_id'])) {
            return (int) $existingMetadata['original_trainer_id'];
        }

        return (int) $scheduledClass->trainer_id;
    }

    /**
     * @param  array<int, mixed>|null  $values
     * @return array<int, int>
     */
    private function ids(?array $values): array
    {
        return collect($values ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    private function timezone(Account $account): string
    {
        return $account->timezone ?: config('app.timezone');
    }
}
