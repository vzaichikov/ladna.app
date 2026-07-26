<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\TelegramAlertRecipientKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Models\TelegramAlert;
use Illuminate\Support\Str;

class QueueTrainerClassCancellationTelegramAlert
{
    public const ReasonStudioCancelled = 'studio_cancelled';

    public const ReasonAllBookingsCancelled = 'all_bookings_cancelled';

    public function __construct(
        private readonly TelegramAlertProducer $alerts,
        private readonly QueueTrainerAssignmentTelegramAlert $assignmentAlerts,
        private readonly TrainerAssignmentTelegramAlertRenderer $assignmentRenderer,
    ) {}

    public function forStudioCancellation(ScheduledClassCancellation $cancellation): ?TelegramAlert
    {
        $cancellation->loadMissing([
            'account.trainerNotificationSetting',
            'effects',
            'scheduledClass.account.trainerNotificationSetting',
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.classType',
            'scheduledClass.trainer',
        ]);

        if ($cancellation->isClosedCorrection() || $cancellation->effects->isEmpty()) {
            return null;
        }

        $scheduledClass = $cancellation->scheduledClass;

        if (! $scheduledClass) {
            return null;
        }

        return $this->queue(
            $scheduledClass,
            self::ReasonStudioCancelled,
            'trainer_class_cancellation:studio:'.$cancellation->id.':trainer:'.($scheduledClass->trainer_id ?? 'none'),
            ['scheduled_class_cancellation_id' => $cancellation->id],
        );
    }

    public function forAllBookingsCancelled(
        ScheduledClass $scheduledClass,
        ClassBooking $triggerBooking,
        ?string $cancellationEventId = null,
    ): ?TelegramAlert {
        $scheduledClass->loadMissing([
            'account.trainerNotificationSetting',
            'location',
            'room',
            'classType',
            'trainer',
        ]);

        if (
            $scheduledClass->status !== ScheduledClassStatus::Scheduled
            || $scheduledClass->starts_at->isPast()
            || $this->hasActiveBookings($scheduledClass)
        ) {
            return null;
        }

        $cancellationEventId ??= (string) Str::uuid();

        return $this->queue(
            $scheduledClass,
            self::ReasonAllBookingsCancelled,
            'trainer_class_cancellation:empty:'.$scheduledClass->id
                .':trainer:'.($scheduledClass->trainer_id ?? 'none')
                .':booking:'.$triggerBooking->id
                .':event:'.$cancellationEventId,
            [
                'trigger_booking_id' => $triggerBooking->id,
                'trigger_booking_persisted' => $triggerBooking->exists,
            ],
        );
    }

    public function supersedePendingAssignmentAlerts(ScheduledClass $scheduledClass, string $reason): int
    {
        return TelegramAlert::query()
            ->where('scheduled_class_id', $scheduledClass->id)
            ->where('type', TelegramAlertType::TrainerAssignment->value)
            ->whereIn('status', [
                TelegramAlertStatus::Pending->value,
                TelegramAlertStatus::Processing->value,
            ])
            ->update([
                'status' => TelegramAlertStatus::Failed->value,
                'next_attempt_at' => null,
                'failed_at' => now(),
                'last_error' => $reason,
            ]);
    }

    public function syncPendingAssignmentAlertsAfterBookingCancellation(ScheduledClass $scheduledClass, ClassBooking $cancelledBooking): void
    {
        $pendingAlerts = TelegramAlert::query()
            ->where('scheduled_class_id', $scheduledClass->id)
            ->where('type', TelegramAlertType::TrainerAssignment->value)
            ->where('status', TelegramAlertStatus::Pending->value);

        if ($scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass) {
            $pendingAlerts
                ->where('class_booking_id', $cancelledBooking->id)
                ->update([
                    'status' => TelegramAlertStatus::Failed->value,
                    'next_attempt_at' => null,
                    'failed_at' => now(),
                    'last_error' => 'booking_cancelled',
                ]);

            return;
        }

        $replacementBooking = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->with('customer')
            ->whereIn('status', [
                ClassBookingStatus::Booked->value,
                ClassBookingStatus::Attended->value,
            ])
            ->orderBy('id')
            ->first();

        if (! $replacementBooking) {
            $this->supersedePendingAssignmentAlerts($scheduledClass, 'all_bookings_cancelled');

            return;
        }

        $scheduledClass->loadMissing(['account', 'location', 'room', 'classType', 'trainer']);
        $payload = $this->assignmentAlerts->payloadFor($scheduledClass, $replacementBooking);
        $pendingAlerts->get()->each(function (TelegramAlert $alert) use ($replacementBooking, $scheduledClass, $payload): void {
            $alert->forceFill([
                'class_booking_id' => $replacementBooking->id,
                'text' => $this->assignmentRenderer->render($scheduledClass->account, $payload),
                'payload' => $payload,
                'attempts' => 0,
                'next_attempt_at' => null,
                'failed_at' => null,
                'last_error' => null,
            ])->save();
        });
    }

    public function supersedePendingCancellationAlerts(ScheduledClassCancellation $cancellation, string $reason): int
    {
        return TelegramAlert::query()
            ->where('type', TelegramAlertType::TrainerClassCancellation->value)
            ->where('dedupe_key', 'like', 'trainer_class_cancellation:studio:'.$cancellation->id.':%')
            ->whereIn('status', [
                TelegramAlertStatus::Pending->value,
                TelegramAlertStatus::Processing->value,
            ])
            ->update([
                'status' => TelegramAlertStatus::Failed->value,
                'next_attempt_at' => null,
                'failed_at' => now(),
                'last_error' => $reason,
            ]);
    }

    public function hasActiveBookings(ScheduledClass $scheduledClass): bool
    {
        return $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->whereIn('status', [
                ClassBookingStatus::Booked->value,
                ClassBookingStatus::Attended->value,
            ])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    private function queue(ScheduledClass $scheduledClass, string $reason, string $dedupeKey, array $extraPayload): ?TelegramAlert
    {
        $account = $scheduledClass->account;

        if (
            ! $account
            || $account->isReadOnlyDemo()
            || ! $account->trainerClassCancellationTelegramAlertsEnabled()
            || ! $scheduledClass->trainer_id
        ) {
            return null;
        }

        return $this->alerts->queue(
            TelegramAlertType::TrainerClassCancellation,
            $account,
            TelegramAlertRecipientKind::Trainer,
            [
                'reason' => $reason,
                'studio_name' => $account->name,
                'trainer_name' => $scheduledClass->trainer?->name,
                'location_name' => $scheduledClass->location?->name,
                'room_name' => $scheduledClass->room?->name,
                'class_name' => $scheduledClass->displayTitle(),
                'class_time' => $this->classTime($scheduledClass),
                'timezone' => $scheduledClass->displayTimezone(),
                ...$extraPayload,
            ],
            [
                'trainer_id' => $scheduledClass->trainer_id,
                'scheduled_class_id' => $scheduledClass->id,
                'class_booking_id' => ($extraPayload['trigger_booking_persisted'] ?? false)
                    ? (int) $extraPayload['trigger_booking_id']
                    : null,
            ],
            $dedupeKey,
        );
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
