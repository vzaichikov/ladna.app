<?php

namespace App\Support;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ManualQuickBookingAvailability
{
    private const SLOT_STEP_MINUTES = 30;

    /**
     * @param  array{date: string, location_id: int, room_id: int, class_type_id: int, trainer_id?: int|null}  $input
     * @return array{
     *     date: string,
     *     timezone: string,
     *     closed: bool,
     *     slots: array<int, array{time: string, starts_at: string, ends_at: string, ends_time: string, label: string, duration_minutes: int}>
     * }
     */
    public function for(Account $account, ScheduleKind $scheduleKind, array $input): array
    {
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

        if ($scheduleKind === ScheduleKind::PrivateLesson && $trainerId) {
            $account->trainers()->whereKey($trainerId)->firstOrFail();
        }

        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $localDate = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $input['date'].' 00:00:00', $timezone);
        $openingHours = $account->openingHoursForIsoWeekday($localDate->isoWeekday());
        $durationMinutes = (int) ($classType->default_duration_minutes ?: 60);

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

        $blockers = $this->blockers($account, $room->id, $trainerId, $opensAt, $closesAt);
        $now = CarbonImmutable::now($timezone);
        $slots = [];

        for ($slotStart = $opensAt; $slotStart->addMinutes($durationMinutes)->lessThanOrEqualTo($closesAt); $slotStart = $slotStart->addMinutes(self::SLOT_STEP_MINUTES)) {
            $slotEnd = $slotStart->addMinutes($durationMinutes);

            if ($slotStart->lessThan($now) || $this->hasOverlap($blockers, $slotStart, $slotEnd)) {
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
     * @param  array{location_id: int, room_id: int, class_type_id: int, trainer_id?: int|null}  $input
     */
    public function hasStart(Account $account, ScheduleKind $scheduleKind, string $startsAt, array $input): bool
    {
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
     * @return Collection<int, mixed>
     */
    private function blockers(Account $account, int $roomId, ?int $trainerId, CarbonImmutable $opensAt, CarbonImmutable $closesAt): Collection
    {
        return $account->scheduledClasses()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where(function ($query) use ($roomId, $trainerId): void {
                $query->where('room_id', $roomId);

                if ($trainerId) {
                    $query->orWhere('trainer_id', $trainerId);
                }
            })
            ->where('starts_at', '<', $closesAt->timezone(config('app.timezone')))
            ->where('ends_at', '>', $opensAt->timezone(config('app.timezone')))
            ->get(['id', 'starts_at', 'ends_at', 'room_id', 'trainer_id']);
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
