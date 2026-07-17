<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertType;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Models\TelegramAlert;
use Illuminate\Support\Str;

class QueueTrainerAssignmentTelegramAlert
{
    public function __construct(
        private readonly TelegramAlertProducer $alerts,
    ) {}

    public function execute(ClassBooking $booking, ?string $dedupeSuffix = null): ?TelegramAlert
    {
        $booking->loadMissing([
            'account',
            'customer',
            'scheduledClass.account',
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.classType',
            'scheduledClass.trainer',
        ]);

        $account = $booking->account ?? $booking->scheduledClass?->account;
        $scheduledClass = $booking->scheduledClass;
        $scheduleKind = $scheduledClass?->classType?->schedule_kind;

        if (! $account || ! $scheduledClass || ! $scheduleKind || ! $this->bookingIsActive($booking)) {
            return null;
        }

        if (! in_array($scheduleKind, [ScheduleKind::GroupClass, ScheduleKind::PrivateLesson], true)) {
            return null;
        }

        if ($scheduleKind === ScheduleKind::GroupClass && ! $this->isFirstActiveGroupBooking($scheduledClass, $booking)) {
            return null;
        }

        return $this->alerts->queue(
            TelegramAlertType::TrainerAssignment,
            $account,
            TelegramAlertRecipientKind::Trainer,
            $this->payloadFor($scheduledClass, $booking),
            [
                'trainer_id' => $scheduledClass->trainer_id,
                'scheduled_class_id' => $scheduledClass->id,
                'class_booking_id' => $booking->id,
            ],
            $this->dedupeKeyFor($scheduledClass, $dedupeSuffix),
        );
    }

    private function bookingIsActive(ClassBooking $booking): bool
    {
        return in_array($booking->status, [ClassBookingStatus::Booked, ClassBookingStatus::Attended], true);
    }

    private function isFirstActiveGroupBooking(ScheduledClass $scheduledClass, ClassBooking $booking): bool
    {
        return ! $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->whereIn('status', [ClassBookingStatus::Booked->value, ClassBookingStatus::Attended->value])
            ->where('id', '<', $booking->id)
            ->exists();
    }

    public function dedupeKeyFor(ScheduledClass $scheduledClass, ?string $suffix = null): ?string
    {
        $scheduleKind = $scheduledClass->classType?->schedule_kind;

        if ($scheduleKind !== ScheduleKind::GroupClass) {
            return null;
        }

        $key = Str::of('trainer_assignment')
            ->append(':group:', (string) $scheduledClass->account_id)
            ->append(':class:', (string) $scheduledClass->id)
            ->append(':trainer:', (string) ($scheduledClass->trainer_id ?? 'none'));

        if (filled($suffix)) {
            $key = $key->append(':', $suffix);
        }

        return $key->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(ScheduledClass $scheduledClass, ClassBooking $booking): array
    {
        return [
            'studio_name' => $scheduledClass->account?->name,
            'trainer_name' => $scheduledClass->trainer?->name,
            'location_name' => $scheduledClass->location?->name,
            'room_name' => $scheduledClass->room?->name,
            'class_name' => $scheduledClass->displayTitle(),
            'class_time' => $this->classTime($scheduledClass),
            'timezone' => $scheduledClass->displayTimezone(),
            'schedule_kind' => $scheduledClass->classType?->schedule_kind?->value,
            'customer_name' => $booking->customer?->name,
        ];
    }

    private function classTime(ScheduledClass $scheduledClass): string
    {
        $timezone = $scheduledClass->displayTimezone();
        $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
        $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);

        if ($startsAt->isSameDay($endsAt)) {
            return $startsAt->format('Y-m-d H:i').' - '.$endsAt->format('H:i');
        }

        return $startsAt->format('Y-m-d H:i').' - '.$endsAt->format('Y-m-d H:i');
    }
}
