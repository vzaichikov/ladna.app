<?php

namespace App\Support;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerPrivateTimeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TrainerPrivateLessonAvailability
{
    public const SLOT_STEP_MINUTES = 30;

    /**
     * @return array{
     *     date: string,
     *     timezone: string,
     *     closed: bool,
     *     slots: array<int, array{time: string, starts_at: string, ends_at: string, ends_time: string, label: string, duration_minutes: int, rooms: array<int, array{id: int, name: string}>}>
     * }
     */
    public function for(Account $account, array $input): array
    {
        $location = $account->locations()->whereKey($input['location_id'])->firstOrFail();
        $classType = $account->classTypes()
            ->whereKey($input['class_type_id'])
            ->where('schedule_kind', ScheduleKind::PrivateLesson->value)
            ->firstOrFail();
        $trainer = $account->trainers()->whereKey($input['trainer_id'])->firstOrFail();
        $customerId = $this->customerIdFor($account, $input['customer_id'] ?? null);
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $allowPast = (bool) ($input['allow_past'] ?? false);

        if (! $this->trainerCanUseLocation($trainer, $location)) {
            return $this->closedResult((string) $input['date'], $timezone);
        }

        $localDate = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $input['date'].' 00:00:00', $timezone);
        $openingHours = $account->openingHoursForIsoWeekday($localDate->isoWeekday());

        if (! $openingHours) {
            return $this->closedResult((string) $input['date'], $timezone);
        }

        $opensAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$openingHours['opens_at'], $timezone);
        $closesAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$openingHours['closes_at'], $timezone);

        if ($closesAt->lessThanOrEqualTo($opensAt)) {
            return $this->closedResult((string) $input['date'], $timezone);
        }

        $durationMinutes = $this->durationMinutes($classType);
        $now = CarbonImmutable::now($timezone);
        $slots = [];

        for ($slotStart = $opensAt; $slotStart->addMinutes($durationMinutes)->lessThanOrEqualTo($closesAt); $slotStart = $slotStart->addMinutes(self::SLOT_STEP_MINUTES)) {
            $slotEnd = $slotStart->addMinutes($durationMinutes);

            if (! $allowPast && $slotStart->lessThan($now)) {
                continue;
            }

            if (! $this->timeframesCoverRange($account, $trainer, $location, $slotStart, $slotEnd)) {
                continue;
            }

            if ($this->trainerHasConflict($account, $trainer, $slotStart, $slotEnd)) {
                continue;
            }

            if ($customerId && $this->customerHasConflict($account, $customerId, $slotStart, $slotEnd)) {
                continue;
            }

            $rooms = $this->freeRoomsForRange($account, $location, $slotStart, $slotEnd);

            if ($rooms->isEmpty()) {
                continue;
            }

            $slots[] = [
                'time' => $slotStart->format('H:i'),
                'starts_at' => $slotStart->format('Y-m-d\TH:i'),
                'ends_at' => $slotEnd->format('Y-m-d\TH:i'),
                'ends_time' => $slotEnd->format('H:i'),
                'label' => $slotStart->format('H:i').'-'.$slotEnd->format('H:i'),
                'duration_minutes' => $durationMinutes,
                'rooms' => $rooms
                    ->map(fn (Room $room): array => [
                        'id' => $room->id,
                        'name' => $room->name,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'date' => (string) $input['date'],
            'timezone' => $timezone,
            'closed' => false,
            'slots' => $slots,
        ];
    }

    /**
     * @param  array{location_id: int, class_type_id: int, trainer_id: int, room_id?: int|null, customer_id?: int|null, allow_past?: bool}  $input
     */
    public function hasStart(Account $account, string $startsAt, array $input): bool
    {
        $date = substr($startsAt, 0, 10);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $availability = $this->for($account, [
            ...$input,
            'date' => $date,
        ]);

        $slot = collect($availability['slots'])
            ->first(fn (array $slot): bool => $slot['starts_at'] === $startsAt);

        if (! $slot) {
            return false;
        }

        $roomId = filled($input['room_id'] ?? null) ? (int) $input['room_id'] : null;

        if (! $roomId) {
            return true;
        }

        return collect($slot['rooms'])
            ->contains(fn (array $room): bool => (int) $room['id'] === $roomId);
    }

    public function featureApplies(Account $account, ScheduleKind $scheduleKind, bool $ignoreTrainerTimeframes = false): bool
    {
        return $scheduleKind === ScheduleKind::PrivateLesson
            && $account->trainerPrivateTimeframesEnabled()
            && ! $ignoreTrainerTimeframes;
    }

    public function trainerCanUseLocation(Trainer $trainer, Location $location): bool
    {
        if ($trainer->account_id !== $location->account_id) {
            return false;
        }

        $assignedLocationIds = $trainer->locations()
            ->where('trainer_location.account_id', $trainer->account_id)
            ->pluck('locations.id');

        return $assignedLocationIds->isEmpty() || $assignedLocationIds->contains($location->id);
    }

    /**
     * @return Collection<int, Location>
     */
    public function locationsForTrainer(Account $account, Trainer $trainer): Collection
    {
        $assignedLocationIds = $trainer->locations()
            ->where('trainer_location.account_id', $account->id)
            ->pluck('locations.id');

        return $account->locations()
            ->active()
            ->when($assignedLocationIds->isNotEmpty(), fn ($query) => $query->whereKey($assignedLocationIds->all()))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Trainer>
     */
    public function trainersForLocation(Account $account, Location $location): Collection
    {
        return $account->trainers()
            ->active()
            ->with('trainerType')
            ->where(function ($query) use ($account, $location): void {
                $query
                    ->whereDoesntHave('locations', fn ($query) => $query->where('trainer_location.account_id', $account->id))
                    ->orWhereHas('locations', fn ($query) => $query
                        ->where('trainer_location.account_id', $account->id)
                        ->whereKey($location->id));
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Room>
     */
    public function freeRoomsForRange(Account $account, Location $location, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Collection
    {
        $rooms = $account->rooms()
            ->active()
            ->where('location_id', $location->id)
            ->orderBy('name')
            ->get();

        if ($rooms->isEmpty()) {
            return $rooms;
        }

        $blockedRoomIds = $account->scheduledClasses()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('location_id', $location->id)
            ->whereIn('room_id', $rooms->pluck('id')->all())
            ->where('starts_at', '<', $endsAt->timezone(config('app.timezone')))
            ->where('ends_at', '>', $startsAt->timezone(config('app.timezone')))
            ->pluck('room_id')
            ->filter()
            ->map(fn (mixed $roomId): int => (int) $roomId)
            ->unique()
            ->values();

        return $rooms
            ->reject(fn (Room $room): bool => $blockedRoomIds->contains($room->id))
            ->values();
    }

    public function cellCanBeSelected(Account $account, Trainer $trainer, Location $location, CarbonImmutable $startsAt): bool
    {
        $endsAt = $startsAt->addMinutes(self::SLOT_STEP_MINUTES);
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');

        if ($startsAt->lessThan(CarbonImmutable::now($timezone))) {
            return false;
        }

        if (! $this->withinTrainerWindow($account, $startsAt)) {
            return false;
        }

        return $this->trainerCanUseLocation($trainer, $location)
            && ! $this->trainerHasConflict($account, $trainer, $startsAt, $endsAt)
            && $this->freeRoomsForRange($account, $location, $startsAt, $endsAt)->isNotEmpty();
    }

    public function timeframesCoverRange(Account $account, Trainer $trainer, Location $location, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $coverageEnd = $startsAt->addMinutes($this->coverageMinutes((int) $startsAt->diffInMinutes($endsAt)));
        $expectedStarts = collect();

        for ($cellStart = $startsAt; $cellStart->lessThan($coverageEnd); $cellStart = $cellStart->addMinutes(self::SLOT_STEP_MINUTES)) {
            $expectedStarts->push($cellStart->timezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        }

        if ($expectedStarts->isEmpty()) {
            return false;
        }

        $storedStarts = $account->trainerPrivateTimeframes()
            ->where('trainer_id', $trainer->id)
            ->where('location_id', $location->id)
            ->where('starts_at', '>=', $startsAt->timezone(config('app.timezone')))
            ->where('starts_at', '<', $coverageEnd->timezone(config('app.timezone')))
            ->pluck('starts_at')
            ->map(fn (mixed $storedStart): string => CarbonImmutable::parse($storedStart, config('app.timezone'))->format('Y-m-d H:i:s'));

        return $expectedStarts->every(fn (string $expectedStart): bool => $storedStarts->contains($expectedStart));
    }

    /**
     * @return array<int, array{
     *     date: string,
     *     label: string,
     *     weekday: string,
     *     closed: bool,
     *     cells: array<int, array{
     *         starts_at: string,
     *         label: string,
     *         hour_label: string|null,
     *         selected: bool,
     *         disabled: bool,
     *         own_class: bool,
     *         fully_occupied: bool
     *     }>
     * }>
     */
    public function timeline(Account $account, Trainer $trainer, Location $location, CarbonImmutable $weekStart): array
    {
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $weekStart = $weekStart->timezone($timezone)->startOfDay();

        if ($weekStart->lessThan($today)) {
            $weekStart = $today;
        }

        $weekEnd = $weekStart->addDays(6)->endOfDay();
        $timeframes = $account->trainerPrivateTimeframes()
            ->where('trainer_id', $trainer->id)
            ->where('location_id', $location->id)
            ->whereBetween('starts_at', [
                $weekStart->timezone(config('app.timezone')),
                $weekEnd->timezone(config('app.timezone')),
            ])
            ->get(['id', 'starts_at'])
            ->mapWithKeys(fn (TrainerPrivateTimeframe $timeframe): array => [
                $timeframe->starts_at->copy()->timezone($timezone)->format('Y-m-d\TH:i') => true,
            ]);
        $ownClasses = $account->scheduledClasses()
            ->where('trainer_id', $trainer->id)
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '<=', $weekEnd->timezone(config('app.timezone')))
            ->where('ends_at', '>=', $weekStart->timezone(config('app.timezone')))
            ->get(['id', 'starts_at', 'ends_at']);

        return collect(range(0, 6))
            ->map(function (int $offset) use ($account, $trainer, $location, $weekStart, $timezone, $timeframes, $ownClasses): array {
                $date = $weekStart->addDays($offset);
                $openingHours = $account->openingHoursForIsoWeekday($date->isoWeekday());

                if (! $openingHours) {
                    return [
                        'date' => $date->toDateString(),
                        'label' => $date->translatedFormat('j F'),
                        'weekday' => $date->translatedFormat('D'),
                        'closed' => true,
                        'cells' => [],
                    ];
                }

                $opensAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $date->toDateString().' '.$openingHours['opens_at'], $timezone);
                $closesAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $date->toDateString().' '.$openingHours['closes_at'], $timezone);
                $cells = [];

                if ($closesAt->lessThanOrEqualTo($opensAt)) {
                    return [
                        'date' => $date->toDateString(),
                        'label' => $date->translatedFormat('j F'),
                        'weekday' => $date->translatedFormat('D'),
                        'closed' => true,
                        'cells' => [],
                    ];
                }

                for ($cellStart = $opensAt; $cellStart->addMinutes(self::SLOT_STEP_MINUTES)->lessThanOrEqualTo($closesAt); $cellStart = $cellStart->addMinutes(self::SLOT_STEP_MINUTES)) {
                    $cellEnd = $cellStart->addMinutes(self::SLOT_STEP_MINUTES);
                    $key = $cellStart->format('Y-m-d\TH:i');
                    $selected = $timeframes->has($key);
                    $ownClass = $this->hasClassOverlap($ownClasses, $cellStart, $cellEnd);
                    $hasFreeRoom = $this->freeRoomsForRange($account, $location, $cellStart, $cellEnd)->isNotEmpty();
                    $canSelect = $this->cellCanBeSelected($account, $trainer, $location, $cellStart);

                    $cells[] = [
                        'starts_at' => $key,
                        'label' => $cellStart->format('H:i'),
                        'hour_label' => $cellStart->minute === 0 ? $cellStart->format('H:i') : null,
                        'selected' => $selected,
                        'disabled' => ! $selected && ! $canSelect,
                        'own_class' => $ownClass,
                        'fully_occupied' => ! $hasFreeRoom,
                    ];
                }

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->translatedFormat('j F'),
                    'weekday' => $date->translatedFormat('D'),
                    'closed' => false,
                    'cells' => $cells,
                ];
            })
            ->all();
    }

    public function withinTrainerWindow(Account $account, CarbonImmutable $startsAt): bool
    {
        $timezone = $startsAt->timezoneName ?: ($account->timezone ?? config('app.timezone'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $lastAllowed = $today->addWeeks($account->trainerPrivateTimeframeWeeks())->endOfDay();

        return $startsAt->greaterThanOrEqualTo($today) && $startsAt->lessThanOrEqualTo($lastAllowed);
    }

    public function toggleCell(Account $account, Trainer $trainer, Location $location, CarbonImmutable $startsAt, bool $selected): bool
    {
        $startsAt = $startsAt->setTime($startsAt->hour, $startsAt->minute, 0);
        $endsAt = $startsAt->addMinutes(self::SLOT_STEP_MINUTES);
        $startsAtForStorage = $startsAt->timezone(config('app.timezone'));

        $query = $account->trainerPrivateTimeframes()
            ->where('trainer_id', $trainer->id)
            ->where('location_id', $location->id)
            ->where('starts_at', $startsAtForStorage);

        if (! $selected) {
            $query->delete();

            return false;
        }

        if (! $this->cellCanBeSelected($account, $trainer, $location, $startsAt)) {
            return false;
        }

        TrainerPrivateTimeframe::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'trainer_id' => $trainer->id,
                'location_id' => $location->id,
                'starts_at' => $startsAtForStorage,
            ],
            [
                'ends_at' => $endsAt->timezone(config('app.timezone')),
            ],
        );

        return true;
    }

    private function durationMinutes(ClassType $classType): int
    {
        return (int) ($classType->default_duration_minutes ?: 60);
    }

    private function coverageMinutes(int $durationMinutes): int
    {
        return (int) (ceil($durationMinutes / self::SLOT_STEP_MINUTES) * self::SLOT_STEP_MINUTES);
    }

    private function trainerHasConflict(Account $account, Trainer $trainer, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return $account->scheduledClasses()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('trainer_id', $trainer->id)
            ->where('starts_at', '<', $endsAt->timezone(config('app.timezone')))
            ->where('ends_at', '>', $startsAt->timezone(config('app.timezone')))
            ->exists();
    }

    private function customerHasConflict(Account $account, int $customerId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $activeBookingStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        return $account->scheduledClasses()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '<', $endsAt->timezone(config('app.timezone')))
            ->where('ends_at', '>', $startsAt->timezone(config('app.timezone')))
            ->whereHas('classBookings', fn ($query) => $query
                ->notCorrectedRemoved()
                ->where('customer_id', $customerId)
                ->whereIn('status', $activeBookingStatuses))
            ->exists();
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     */
    private function hasClassOverlap(Collection $classes, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $startsAt = $startsAt->timezone(config('app.timezone'));
        $endsAt = $endsAt->timezone(config('app.timezone'));

        return $classes->contains(fn (ScheduledClass $scheduledClass): bool => $scheduledClass->starts_at->lessThan($endsAt)
            && $scheduledClass->ends_at->greaterThan($startsAt));
    }

    private function customerIdFor(Account $account, mixed $customerId): ?int
    {
        if (blank($customerId)) {
            return null;
        }

        $customerId = (int) $customerId;
        $account->customers()->whereKey($customerId)->firstOrFail();

        return $customerId;
    }

    /**
     * @return array{date: string, timezone: string, closed: bool, slots: array<int, mixed>}
     */
    private function closedResult(string $date, string $timezone): array
    {
        return [
            'date' => $date,
            'timezone' => $timezone,
            'closed' => true,
            'slots' => [],
        ];
    }
}
