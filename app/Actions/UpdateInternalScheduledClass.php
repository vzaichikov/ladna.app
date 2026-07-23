<?php

namespace App\Actions;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\ActorSnapshot;
use App\Support\ScheduleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateInternalScheduledClass
{
    public function __construct(
        private readonly ScheduleOccupancy $scheduleOccupancy,
        private readonly ActorSnapshot $actorSnapshot,
        private readonly SyncScheduledClassTrainerAlerts $syncTrainerAlerts,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Account $account, ScheduledClass $scheduledClass, array $validated, ?User $actor): ScheduledClass
    {
        return DB::transaction(function () use ($account, $scheduledClass, $validated, $actor): ScheduledClass {
            $this->scheduleOccupancy->lockAccount($account);

            $lockedClass = ScheduledClass::query()
                ->with(['classType', 'trainer', 'additionalTrainers', 'peopleCount'])
                ->whereBelongsTo($account)
                ->whereKey($scheduledClass->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedClass->classType?->schedule_kind !== ScheduleKind::InternalClass
                || ! $lockedClass->isFullyEditableOccurrence()) {
                throw ValidationException::withMessages([
                    '_form' => __('app.internal_class_edit_unavailable'),
                ]);
            }

            $location = $account->locations()->active()->whereKey($validated['location_id'])->firstOrFail();
            $room = $account->rooms()
                ->active()
                ->whereBelongsTo($location)
                ->whereKey($validated['room_id'])
                ->firstOrFail();
            $classType = $account->classTypes()
                ->active()
                ->whereKey($validated['class_type_id'])
                ->where('schedule_kind', ScheduleKind::InternalClass->value)
                ->firstOrFail();
            $trainer = $account->trainers()->active()->whereKey($validated['trainer_id'])->firstOrFail();
            $additionalTrainers = $this->additionalTrainers($account, $validated, $trainer->id);
            $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['starts_at'], $timezone)
                ->timezone(config('app.timezone'));
            $endsAt = $startsAt->addMinutes((int) $validated['duration_minutes']);

            if (! $startsAt->isFuture()) {
                throw ValidationException::withMessages([
                    'starts_at' => __('app.internal_class_start_must_be_future'),
                ]);
            }

            $trainerIds = collect([$trainer->id])
                ->merge($additionalTrainers->modelKeys())
                ->map(fn (mixed $trainerId): int => (int) $trainerId)
                ->values()
                ->all();
            $this->scheduleOccupancy->lockResources($account, $room->id, $trainerIds);
            $this->scheduleOccupancy->assertAvailable(
                $account,
                $room->id,
                $trainerIds,
                $startsAt,
                $endsAt,
                $lockedClass->id,
            );

            $metadata = $lockedClass->metadata ?? [];
            $metadata['source'] = 'manual';
            $metadata['schedule_kind'] = ScheduleKind::InternalClass->value;
            $trainerChange = null;

            if ($lockedClass->trainer_id !== $trainer->id) {
                $trainerChange = $lockedClass->trainerChanges()->create([
                    'account_id' => $account->id,
                    'previous_trainer_id' => $lockedClass->trainer_id,
                    'new_trainer_id' => $trainer->id,
                    'previous_trainer_name' => $lockedClass->trainer?->name,
                    'new_trainer_name' => $trainer->name,
                    ...$this->actorSnapshot->capture($account, $actor),
                ]);
                unset($metadata[SyncTrainerSubstitutions::MetadataKey]);
                $metadata[ScheduledClass::MANUAL_TRAINER_OVERRIDE_METADATA_KEY] = [
                    'trainer_change_id' => $trainerChange->id,
                    'trainer_id' => $trainer->id,
                    'changed_at' => now()->toIso8601String(),
                ];
            }

            $lockedClass->forceFill([
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'title' => trim((string) $validated['title']),
                'description' => $validated['description'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'capacity' => null,
                'booking_cutoff_minutes' => null,
                'cancellation_cutoff_minutes' => null,
                'is_public' => false,
                'is_manually_modified' => true,
                'metadata' => $metadata,
            ])->save();

            $lockedClass->additionalTrainers()->syncWithPivotValues(
                $additionalTrainers->modelKeys(),
                ['account_id' => $account->id],
            );
            $lockedClass->peopleCount()->update([
                'location_id' => $location->id,
                'room_id' => $room->id,
                'trainer_id' => $trainer->id,
            ]);
            $lockedClass->setRelation('trainer', $trainer);
            $lockedClass->setRelation('additionalTrainers', $additionalTrainers);

            if ($trainerChange) {
                $this->syncTrainerAlerts->execute($account, $lockedClass, $trainerChange);
            }

            return $lockedClass->refresh()->load('additionalTrainers');
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Collection<int, Trainer>
     */
    private function additionalTrainers(Account $account, array $validated, int $mainTrainerId): Collection
    {
        $trainerIds = collect($validated['additional_trainer_ids'] ?? [])
            ->map(fn (mixed $trainerId): int => (int) $trainerId)
            ->filter(fn (int $trainerId): bool => $trainerId > 0)
            ->unique()
            ->values();

        if ($trainerIds->contains($mainTrainerId)) {
            throw ValidationException::withMessages([
                'additional_trainer_ids' => __('app.additional_trainer_cannot_be_main'),
            ]);
        }

        $trainers = $account->trainers()
            ->active()
            ->whereKey($trainerIds->all())
            ->orderBy('id')
            ->get();

        if ($trainers->count() !== $trainerIds->count()) {
            throw ValidationException::withMessages([
                'additional_trainer_ids' => __('app.additional_trainers_invalid'),
            ]);
        }

        return $trainers;
    }
}
