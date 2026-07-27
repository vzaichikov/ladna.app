<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ScheduledClass;
use Illuminate\Validation\ValidationException;

class ResolvePublicGroupBookingSelection
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(Account $account, Location $location, ?Customer $customer, int $scheduledClassId): array
    {
        $scheduledClass = $account->scheduledClasses()
            ->with(['classType', 'room', 'trainer'])
            ->whereKey($scheduledClassId)
            ->where('location_id', $location->id)
            ->first();

        if (
            ! $scheduledClass
            || ! $scheduledClass->is_public
            || $scheduledClass->status !== ScheduledClassStatus::Scheduled
            || $scheduledClass->starts_at->lessThan(now())
            || $scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass
        ) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.quick_booking_group_class_invalid'),
            ]);
        }

        if (! $scheduledClass->isBookingOpen()) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.booking_cutoff_closed'),
            ]);
        }

        $this->ensureCapacity($scheduledClass, $customer);

        $timezone = $scheduledClass->displayTimezone();
        $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
        $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);

        return [
            'scheduleKind' => ScheduleKind::GroupClass,
            'scheduledClassId' => $scheduledClass->id,
            'title' => $scheduledClass->title,
            'dateLabel' => $startsAt->translatedFormat('l, j F'),
            'timeLabel' => $startsAt->format('H:i').' - '.$endsAt->format('H:i'),
            'durationLabel' => $scheduledClass->durationMinutes().' '.__('app.minutes'),
            'trainerLabel' => $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned'),
            'roomLabel' => $scheduledClass->room?->name ?? $location->name,
            'hiddenFields' => [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
            ],
            'backUrl' => route('public.schedule', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'kind' => ScheduleKind::GroupClass->value,
                'date' => $startsAt->toDateString(),
            ]),
        ];
    }

    private function ensureCapacity(ScheduledClass $scheduledClass, ?Customer $customer): void
    {
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        if ($customer && $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->where('customer_id', $customer->id)
            ->whereIn('status', $activeStatuses)
            ->exists()) {
            return;
        }

        $capacity = (int) ($scheduledClass->capacity ?? 0);
        $activeBookingsCount = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->whereIn('status', $activeStatuses)
            ->count();

        if ($capacity <= 0 || $activeBookingsCount >= $capacity) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.no_available_group_slots'),
            ]);
        }
    }
}
