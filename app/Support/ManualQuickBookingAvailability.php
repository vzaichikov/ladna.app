<?php

namespace App\Support;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ManualQuickBookingAvailability
{
    private const SLOT_STEP_MINUTES = 30;

    public function __construct(
        private readonly TrainerPrivateLessonAvailability $trainerPrivateLessonAvailability,
        private readonly TrainerActivityDirectionEligibility $trainerActivityDirectionEligibility,
    ) {}

    /**
     * @param  array{date: string, location_id: int, room_id?: int|null, class_type_id: int, trainer_id?: int|null, customer_id?: int|null, allow_past?: bool, ignore_trainer_timeframes?: bool, activity_direction_id?: int|null}  $input
     * @return array{
     *     date: string,
     *     timezone: string,
     *     closed: bool,
     *     slots: array<int, array{time: string, starts_at: string, ends_at: string, ends_time: string, label: string, duration_minutes: int}>
     * }
     */
    public function for(Account $account, ScheduleKind $scheduleKind, array $input): array
    {
        if ($this->trainerPrivateLessonAvailability->featureApplies($account, $scheduleKind, (bool) ($input['ignore_trainer_timeframes'] ?? false))) {
            return $this->trainerPrivateLessonAvailability->for($account, $input);
        }

        $location = $account->locations()->whereKey($input['location_id'])->firstOrFail();
        $room = $account->rooms()
            ->whereKey($input['room_id'])
            ->where('location_id', $location->id)
            ->firstOrFail();
        $classType = $account->classTypes()
            ->whereKey($input['class_type_id'])
            ->where('schedule_kind', $scheduleKind->value)
            ->firstOrFail();
        $trainerId = filled($input['trainer_id'] ?? null) ? (int) $input['trainer_id'] : null;
        $trainer = $trainerId ? $account->trainers()->whereKey($trainerId)->firstOrFail() : null;
        $activityDirectionId = $this->trainerActivityDirectionEligibility->activeDirectionId($account, $input['activity_direction_id'] ?? null);
        $customerId = $this->customerIdFor($account, $input['customer_id'] ?? null);

        if (
            $scheduleKind === ScheduleKind::PrivateLesson
            && (! $trainer || ! $this->trainerActivityDirectionEligibility->trainerCanHandle($account, $trainer, $classType, $activityDirectionId))
        ) {
            return [
                'date' => $input['date'],
                'timezone' => $location->timezone ?? $account->timezone ?? config('app.timezone'),
                'closed' => false,
                'slots' => [],
            ];
        }

        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $localDate = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $input['date'].' 00:00:00', $timezone);
        $openingHours = $account->openingHoursForIsoWeekday($localDate->isoWeekday());
        $durationMinutes = (int) ($classType->default_duration_minutes ?: 60);
        $allowPast = (bool) ($input['allow_past'] ?? false);

        if (! $openingHours) {
            return [
                'date' => $input['date'],
                'timezone' => $timezone,
                'closed' => true,
                'slots' => [],
            ];
        }

        $opensAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$openingHours['opens_at'], $timezone);
        $closesAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$openingHours['closes_at'], $timezone);

        if ($closesAt->lessThanOrEqualTo($opensAt)) {
            return [
                'date' => $input['date'],
                'timezone' => $timezone,
                'closed' => true,
                'slots' => [],
            ];
        }

        $blockers = $this->blockers($account, $room->id, $trainerId, $customerId, $opensAt, $closesAt);
        $now = CarbonImmutable::now($timezone);
        $slots = [];

        for ($slotStart = $opensAt; $slotStart->addMinutes($durationMinutes)->lessThanOrEqualTo($closesAt); $slotStart = $slotStart->addMinutes(self::SLOT_STEP_MINUTES)) {
            $slotEnd = $slotStart->addMinutes($durationMinutes);

            if ((! $allowPast && $slotStart->lessThan($now)) || $this->hasOverlap($blockers, $slotStart, $slotEnd)) {
                continue;
            }

            $slots[] = [
                'time' => $slotStart->format('H:i'),
                'starts_at' => $slotStart->format('Y-m-d\TH:i'),
                'ends_at' => $slotEnd->format('Y-m-d\TH:i'),
                'ends_time' => $slotEnd->format('H:i'),
                'label' => $slotStart->format('H:i').'-'.$slotEnd->format('H:i'),
                'duration_minutes' => $durationMinutes,
            ];
        }

        return [
            'date' => $input['date'],
            'timezone' => $timezone,
            'closed' => false,
            'slots' => $slots,
        ];
    }

    /**
     * @param  array{location_id: int, room_id?: int|null, class_type_id: int, trainer_id?: int|null, customer_id?: int|null, allow_past?: bool, ignore_trainer_timeframes?: bool, activity_direction_id?: int|null}  $input
     */
    public function hasStart(Account $account, ScheduleKind $scheduleKind, string $startsAt, array $input): bool
    {
        if ($this->trainerPrivateLessonAvailability->featureApplies($account, $scheduleKind, (bool) ($input['ignore_trainer_timeframes'] ?? false))) {
            return $this->trainerPrivateLessonAvailability->hasStart($account, $startsAt, $input);
        }

        $date = substr($startsAt, 0, 10);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $availability = $this->for($account, $scheduleKind, [
            ...$input,
            'date' => $date,
        ]);

        return collect($availability['slots'])
            ->contains(fn (array $slot): bool => $slot['starts_at'] === $startsAt);
    }

    /**
     * @param  array{location_id: int, room_id: int, class_type_id: int, trainer_id?: int|null, customer_id?: int|null, allow_past?: bool}  $input
     */
    public function hasRange(Account $account, ScheduleKind $scheduleKind, string $startsAt, string $endsAt, array $input): bool
    {
        if ($scheduleKind !== ScheduleKind::RoomRental) {
            return false;
        }

        $location = $account->locations()->whereKey($input['location_id'])->firstOrFail();
        $room = $account->rooms()
            ->whereKey($input['room_id'])
            ->where('location_id', $location->id)
            ->firstOrFail();
        $account->classTypes()
            ->whereKey($input['class_type_id'])
            ->where('schedule_kind', $scheduleKind->value)
            ->firstOrFail();
        $trainerId = filled($input['trainer_id'] ?? null) ? (int) $input['trainer_id'] : null;
        $customerId = $this->customerIdFor($account, $input['customer_id'] ?? null);

        if ($trainerId) {
            $account->trainers()->whereKey($trainerId)->firstOrFail();
        }

        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $slotStart = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $startsAt, $timezone);
        $slotEnd = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $endsAt, $timezone);
        $allowPast = (bool) ($input['allow_past'] ?? false);

        if ($slotEnd->lessThanOrEqualTo($slotStart) || (! $allowPast && $slotStart->lessThan(CarbonImmutable::now($timezone)))) {
            return false;
        }

        $localDate = $slotStart->startOfDay();

        if (! $slotEnd->isSameDay($slotStart)) {
            return false;
        }

        $openingHours = $account->openingHoursForIsoWeekday($localDate->isoWeekday());

        if (! $openingHours) {
            return false;
        }

        $opensAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $slotStart->format('Y-m-d').' '.$openingHours['opens_at'], $timezone);
        $closesAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $slotStart->format('Y-m-d').' '.$openingHours['closes_at'], $timezone);

        if ($closesAt->lessThanOrEqualTo($opensAt) || $slotStart->lessThan($opensAt) || $slotEnd->greaterThan($closesAt)) {
            return false;
        }

        return ! $this->hasOverlap(
            $this->blockers($account, $room->id, $trainerId, $customerId, $slotStart, $slotEnd),
            $slotStart,
            $slotEnd,
        );
    }

    /**
     * @return Collection<int, mixed>
     */
    private function blockers(Account $account, int $roomId, ?int $trainerId, ?int $customerId, CarbonImmutable $opensAt, CarbonImmutable $closesAt): Collection
    {
        $activeBookingStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        return $account->scheduledClasses()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where(function ($query) use ($roomId, $trainerId, $customerId, $activeBookingStatuses): void {
                $query->where('room_id', $roomId);

                if ($trainerId) {
                    $query->orWhere(function ($query) use ($trainerId): void {
                        $query
                            ->where('trainer_id', $trainerId)
                            ->orWhereHas('additionalTrainers', fn ($query) => $query->whereKey($trainerId));
                    });
                }

                if ($customerId) {
                    $query->orWhereHas('classBookings', fn ($query) => $query
                        ->notCorrectedRemoved()
                        ->where('customer_id', $customerId)
                        ->whereIn('status', $activeBookingStatuses));
                }
            })
            ->where('starts_at', '<', $closesAt->timezone(config('app.timezone')))
            ->where('ends_at', '>', $opensAt->timezone(config('app.timezone')))
            ->get(['id', 'starts_at', 'ends_at', 'room_id', 'trainer_id']);
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
     * @param  Collection<int, mixed>  $blockers
     */
    private function hasOverlap(Collection $blockers, CarbonImmutable $slotStart, CarbonImmutable $slotEnd): bool
    {
        $slotStart = $slotStart->timezone(config('app.timezone'));
        $slotEnd = $slotEnd->timezone(config('app.timezone'));

        return $blockers->contains(fn ($blocker): bool => $blocker->starts_at->lessThan($slotEnd)
            && $blocker->ends_at->greaterThan($slotStart));
    }
}
