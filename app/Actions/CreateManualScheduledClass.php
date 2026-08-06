<?php

namespace App\Actions;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\RoomActivityDirectionEligibility;
use App\Support\ScheduleKindRegistry;
use App\Support\ScheduleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateManualScheduledClass
{
    private readonly ScheduleOccupancy $scheduleOccupancy;

    private readonly RoomActivityDirectionEligibility $roomActivityDirectionEligibility;

    public function __construct(
        ?ScheduleOccupancy $scheduleOccupancy = null,
        ?RoomActivityDirectionEligibility $roomActivityDirectionEligibility = null,
    ) {
        $this->scheduleOccupancy = $scheduleOccupancy ?? app(ScheduleOccupancy::class);
        $this->roomActivityDirectionEligibility = $roomActivityDirectionEligibility ?? app(RoomActivityDirectionEligibility::class);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Account $account, ScheduleKind $scheduleKind, array $validated): ScheduledClass
    {
        return DB::transaction(function () use ($account, $scheduleKind, $validated): ScheduledClass {
            $this->scheduleOccupancy->lockAccount($account);
            $account->refresh();

            $location = $account->locations()->active()->whereKey($validated['location_id'])->firstOrFail();
            $room = $account->rooms()
                ->active()
                ->whereBelongsTo($location)
                ->whereKey($validated['room_id'])
                ->firstOrFail();
            $classType = $account->classTypes()
                ->active()
                ->whereKey($validated['class_type_id'])
                ->where('schedule_kind', $scheduleKind->value)
                ->firstOrFail();
            $trainer = filled($validated['trainer_id'] ?? null)
                ? $account->trainers()->active()->whereKey($validated['trainer_id'])->firstOrFail()
                : null;
            $additionalTrainers = $this->additionalTrainers($account, $scheduleKind, $validated, $trainer?->id);
            $definition = ScheduleKindRegistry::get($scheduleKind);

            if (! $this->roomActivityDirectionEligibility->roomCanHost($account, $room, $classType)) {
                throw ValidationException::withMessages([
                    'room_id' => __('app.room_activity_direction_mismatch'),
                ]);
            }

            if ((bool) $definition['trainer_required'] && ! $trainer) {
                throw ValidationException::withMessages([
                    'trainer_id' => __('app.trainer_required'),
                ]);
            }

            $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['starts_at'], $timezone);
            $durationMinutes = (int) (($validated['duration_minutes'] ?? null) ?: $classType->default_duration_minutes);
            $endsAt = $startsAt->addMinutes($durationMinutes);
            $databaseStartsAt = $startsAt->timezone(config('app.timezone'));
            $databaseEndsAt = $endsAt->timezone(config('app.timezone'));

            $trainerIds = collect([$trainer?->id])
                ->merge($additionalTrainers->modelKeys())
                ->filter()
                ->map(fn (mixed $trainerId): int => (int) $trainerId)
                ->values()
                ->all();

            $this->scheduleOccupancy->lockResources($account, $room->id, $trainerIds);
            $this->scheduleOccupancy->assertRoomAvailable(
                $account,
                $room->id,
                $databaseStartsAt,
                $databaseEndsAt,
            );

            $hasTrainerConflict = $this->scheduleOccupancy->hasTrainerConflict(
                $account,
                $trainerIds,
                $databaseStartsAt,
                $databaseEndsAt,
            );
            $allowsManualTrainerOverlap = $account->allowsManualTrainerOverlap();
            $trainerOverlapConfirmed = $hasTrainerConflict
                && $allowsManualTrainerOverlap
                && (bool) ($validated['confirm_trainer_overlap'] ?? false);

            if ($hasTrainerConflict && ! $trainerOverlapConfirmed) {
                throw ValidationException::withMessages([
                    ($allowsManualTrainerOverlap ? 'confirm_trainer_overlap' : 'starts_at') => $allowsManualTrainerOverlap
                        ? __('app.confirm_trainer_overlap_warning')
                        : __('app.manual_slot_unavailable'),
                ]);
            }

            $isCustomerBookable = (bool) $definition['customer_bookable'];
            $metadata = [
                'source' => 'manual',
                'schedule_kind' => $scheduleKind->value,
            ];

            if ($trainerOverlapConfirmed) {
                $metadata['trainer_overlap_confirmed'] = true;
            }

            $scheduledClass = $account->scheduledClasses()->create([
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer?->id,
                'schedule_series_id' => null,
                'title' => filled($validated['title'] ?? null) ? trim((string) $validated['title']) : $classType->name,
                'description' => $scheduleKind === ScheduleKind::InternalClass
                    ? ($validated['description'] ?? null)
                    : (($validated['description'] ?? null) ?: $classType->description),
                'starts_at' => $databaseStartsAt,
                'ends_at' => $databaseEndsAt,
                'capacity' => $isCustomerBookable
                    ? (($validated['capacity'] ?? null) ?? $classType->default_capacity ?? $room->capacity)
                    : null,
                'booking_cutoff_minutes' => $isCustomerBookable
                    ? (($validated['booking_cutoff_minutes'] ?? null) ?? $classType->booking_cutoff_minutes)
                    : null,
                'cancellation_cutoff_minutes' => $isCustomerBookable
                    ? (($validated['cancellation_cutoff_minutes'] ?? null) ?? $classType->cancellation_cutoff_minutes)
                    : null,
                'is_generated' => false,
                'is_manually_modified' => false,
                'metadata' => $metadata,
                'is_public' => (bool) $definition['default_is_public'],
                'status' => ScheduledClassStatus::Scheduled->value,
            ]);

            if ($scheduleKind === ScheduleKind::InternalClass) {
                $scheduledClass->additionalTrainers()->syncWithPivotValues(
                    $additionalTrainers->modelKeys(),
                    ['account_id' => $account->id],
                );
            }

            return $scheduledClass->load('additionalTrainers');
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Collection<int, Trainer>
     */
    private function additionalTrainers(
        Account $account,
        ScheduleKind $scheduleKind,
        array $validated,
        ?int $mainTrainerId,
    ): Collection {
        if ($scheduleKind !== ScheduleKind::InternalClass) {
            return new Collection;
        }

        $trainerIds = collect($validated['additional_trainer_ids'] ?? [])
            ->map(fn (mixed $trainerId): int => (int) $trainerId)
            ->filter(fn (int $trainerId): bool => $trainerId > 0)
            ->unique()
            ->values();

        if ($mainTrainerId && $trainerIds->contains($mainTrainerId)) {
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
