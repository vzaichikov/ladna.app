<?php

namespace App\Support\Telegram;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\ManualQuickBookingAvailability;
use App\Support\RoomActivityDirectionEligibility;
use App\Support\TrainerActivityDirectionEligibility;
use App\Support\TrainerPrivateLessonAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerTelegramPrivateLessonOptions
{
    public function __construct(
        private readonly ManualQuickBookingAvailability $manualQuickBookingAvailability,
        private readonly TrainerPrivateLessonAvailability $trainerPrivateLessonAvailability,
        private readonly TrainerActivityDirectionEligibility $trainerActivityDirectionEligibility,
        private readonly RoomActivityDirectionEligibility $roomActivityDirectionEligibility,
    ) {}

    public function isConfigured(Account $account): bool
    {
        return $account->hasScheduleKindEnabled(ScheduleKind::PrivateLesson)
            && $this->locations($account)->isNotEmpty()
            && $account->classTypes()->active()->where('schedule_kind', ScheduleKind::PrivateLesson->value)->exists()
            && $account->trainers()->active()->exists();
    }

    /**
     * @return Collection<int, Location>
     */
    public function locations(Account $account): Collection
    {
        return $account->locations()
            ->active()
            ->whereHas('rooms', fn (Builder $query): Builder => $query->active())
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'timezone']);
    }

    /**
     * @return Collection<int, ActivityDirection>
     */
    public function directions(Account $account): Collection
    {
        return $account->activityDirections()
            ->active()
            ->orderBy('name')
            ->get(['id', 'account_id', 'name']);
    }

    /**
     * @return Collection<int, ClassType>
     */
    public function classTypes(Account $account, ?int $activityDirectionId): Collection
    {
        return $account->classTypes()
            ->active()
            ->where('schedule_kind', ScheduleKind::PrivateLesson->value)
            ->when($activityDirectionId, fn (Builder $query): Builder => $query
                ->where(function (Builder $query) use ($activityDirectionId): void {
                    $query
                        ->whereNull('activity_direction_id')
                        ->orWhere('activity_direction_id', $activityDirectionId);
                }))
            ->with('activityDirection:id,account_id,is_active')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Trainer>
     */
    public function trainers(Account $account, Location $location, ClassType $classType, ?int $activityDirectionId): Collection
    {
        $effectiveDirectionId = $this->trainerActivityDirectionEligibility->effectiveDirectionId(
            $account,
            $classType,
            $activityDirectionId,
        );

        if ($this->usesTrainerTimeframes($account)) {
            return $this->trainerPrivateLessonAvailability
                ->trainersForLocation($account, $location, $effectiveDirectionId)
                ->filter(fn (Trainer $trainer): bool => $this->trainerActivityDirectionEligibility
                    ->trainerCanHandle($account, $trainer, $classType, $activityDirectionId))
                ->values();
        }

        return $account->trainers()
            ->active()
            ->with(['trainerType', 'activityDirections'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Trainer $trainer): bool => $this->trainerActivityDirectionEligibility
                ->trainerCanHandle($account, $trainer, $classType, $activityDirectionId))
            ->values();
    }

    /**
     * @return Collection<int, Room>
     */
    public function rooms(Account $account, Location $location, ClassType $classType, ?int $activityDirectionId): Collection
    {
        $rooms = $account->rooms()
            ->active()
            ->where('location_id', $location->id)
            ->with('activityDirections')
            ->orderBy('name')
            ->get();

        return $this->roomActivityDirectionEligibility->filterRooms(
            $rooms,
            $account,
            $classType,
            $activityDirectionId,
        );
    }

    public function usesTrainerTimeframes(Account $account): bool
    {
        return $this->trainerPrivateLessonAvailability->featureApplies(
            $account,
            ScheduleKind::PrivateLesson,
        );
    }

    /**
     * Return dates worth showing before the exact per-slot conflict checks run.
     *
     * @param  Collection<int, CarbonInterface>  $dates
     * @return Collection<int, CarbonInterface>
     */
    public function candidateDates(
        Account $account,
        Location $location,
        ClassType $classType,
        Trainer $trainer,
        Collection $dates,
    ): Collection {
        $dates = $dates
            ->filter(fn (CarbonInterface $date): bool => $account->openingHoursForIsoWeekday($date->isoWeekday()) !== null)
            ->values();

        if ($dates->isEmpty() || ! $this->usesTrainerTimeframes($account)) {
            return $dates;
        }

        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $rangeStartsAt = CarbonImmutable::instance($dates->first())->startOfDay()->timezone(config('app.timezone'));
        $rangeEndsAt = CarbonImmutable::instance($dates->last())->endOfDay()->timezone(config('app.timezone'));
        $timeframeStarts = $account->trainerPrivateTimeframes()
            ->where('trainer_id', $trainer->id)
            ->where('location_id', $location->id)
            ->whereBetween('starts_at', [$rangeStartsAt, $rangeEndsAt])
            ->pluck('starts_at')
            ->mapWithKeys(fn (mixed $startsAt): array => [
                CarbonImmutable::parse($startsAt, config('app.timezone'))->timezone($timezone)->format('Y-m-d H:i:s') => true,
            ]);
        $requiredCells = max(1, (int) ceil(
            (int) ($classType->default_duration_minutes ?: 60)
            / TrainerPrivateLessonAvailability::SLOT_STEP_MINUTES,
        ));

        return $dates
            ->filter(function (CarbonInterface $date) use ($timeframeStarts, $requiredCells, $timezone): bool {
                $datePrefix = $date->format('Y-m-d').' ';

                return $timeframeStarts->keys()
                    ->filter(fn (string $startsAt): bool => str_starts_with($startsAt, $datePrefix))
                    ->contains(function (string $startsAt) use ($timeframeStarts, $requiredCells, $timezone): bool {
                        $firstCell = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $startsAt, $timezone);

                        return collect(range(0, $requiredCells - 1))
                            ->every(fn (int $offset): bool => $timeframeStarts->has(
                                $firstCell
                                    ->addMinutes($offset * TrainerPrivateLessonAvailability::SLOT_STEP_MINUTES)
                                    ->format('Y-m-d H:i:s'),
                            ));
                    });
            })
            ->values();
    }

    /**
     * @return array{
     *     date: string,
     *     timezone: string,
     *     closed: bool,
     *     slots: array<int, array<string, mixed>>
     * }
     */
    public function availability(
        Account $account,
        Customer $customer,
        Location $location,
        ClassType $classType,
        Trainer $trainer,
        ?Room $room,
        ?int $activityDirectionId,
        string $date,
    ): array {
        return $this->manualQuickBookingAvailability->for($account, ScheduleKind::PrivateLesson, [
            'date' => $date,
            'location_id' => $location->id,
            'room_id' => $room?->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer->id,
            'customer_id' => $customer->id,
            'activity_direction_id' => $activityDirectionId,
        ]);
    }

    public function previewClass(
        Account $account,
        Location $location,
        ClassType $classType,
        Trainer $trainer,
        Room $room,
        string $startsAt,
    ): ScheduledClass {
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $localStartsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $startsAt, $timezone);
        $durationMinutes = (int) ($classType->default_duration_minutes ?: 60);
        $scheduledClass = new ScheduledClass([
            'account_id' => $account->id,
            'location_id' => $location->id,
            'room_id' => $room->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer->id,
            'title' => $classType->name,
            'starts_at' => $localStartsAt->timezone(config('app.timezone')),
            'ends_at' => $localStartsAt->addMinutes($durationMinutes)->timezone(config('app.timezone')),
            'capacity' => 1,
            'booking_cutoff_minutes' => $classType->booking_cutoff_minutes,
            'cancellation_cutoff_minutes' => $classType->cancellation_cutoff_minutes,
        ]);
        $scheduledClass->setRelations([
            'account' => $account,
            'location' => $location,
            'room' => $room,
            'classType' => $classType,
            'trainer' => $trainer,
        ]);

        return $scheduledClass;
    }
}
