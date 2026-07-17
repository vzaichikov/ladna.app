<?php

namespace App\Actions;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\TelegramAlertStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassTrainerChange;
use App\Models\TelegramAlert;
use App\Support\Telegram\Alerts\QueueTrainerAssignmentTelegramAlert;
use App\Support\Telegram\Alerts\TrainerAssignmentTelegramAlertRenderer;
use Illuminate\Support\Collection;

class SyncScheduledClassTrainerAlerts
{
    public function __construct(
        private readonly QueueTrainerAssignmentTelegramAlert $queueAlert,
        private readonly TrainerAssignmentTelegramAlertRenderer $renderer,
    ) {}

    public function execute(Account $account, ScheduledClass $scheduledClass, ScheduledClassTrainerChange $change): void
    {
        if (
            $scheduledClass->status !== ScheduledClassStatus::Scheduled
            || ! $scheduledClass->starts_at->isFuture()
            || ! $scheduledClass->trainer_id
        ) {
            return;
        }

        $bookings = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->with('customer')
            ->whereIn('status', [ClassBookingStatus::Booked->value, ClassBookingStatus::Attended->value])
            ->orderBy('id')
            ->get();
        $pendingAlerts = $account->telegramAlerts()
            ->whereBelongsTo($scheduledClass, 'scheduledClass')
            ->where('type', TelegramAlertType::TrainerAssignment->value)
            ->where('status', TelegramAlertStatus::Pending->value)
            ->lockForUpdate()
            ->get();

        if ($bookings->isEmpty()) {
            $this->supersedePendingAlerts($pendingAlerts);

            return;
        }

        $targetBookings = $scheduledClass->classType?->schedule_kind === ScheduleKind::GroupClass
            ? $bookings->take(1)
            : $bookings;
        $usedAlertIds = collect();

        $targetBookings->each(function (ClassBooking $booking) use ($scheduledClass, $change, $pendingAlerts, $usedAlertIds): void {
            $pendingAlert = $this->pendingAlertFor($pendingAlerts, $booking, $scheduledClass);

            if ($pendingAlert) {
                $this->retargetPendingAlert($pendingAlert, $booking, $scheduledClass, $change);
                $usedAlertIds->push($pendingAlert->id);

                return;
            }

            $this->queueAlert->execute($booking, 'trainer-change:'.$change->id);
        });

        $this->supersedePendingAlerts(
            $pendingAlerts->reject(fn (TelegramAlert $alert): bool => $usedAlertIds->contains($alert->id)),
        );
    }

    /**
     * @param  Collection<int, TelegramAlert>  $alerts
     */
    private function supersedePendingAlerts(Collection $alerts): void
    {
        $alerts->each(fn (TelegramAlert $alert) => $alert->forceFill([
            'status' => TelegramAlertStatus::Failed->value,
            'next_attempt_at' => null,
            'failed_at' => now(),
            'last_error' => 'trainer_reassigned',
        ])->save());
    }

    /**
     * @param  Collection<int, TelegramAlert>  $pendingAlerts
     */
    private function pendingAlertFor(Collection $pendingAlerts, ClassBooking $booking, ScheduledClass $scheduledClass): ?TelegramAlert
    {
        if ($scheduledClass->classType?->schedule_kind === ScheduleKind::GroupClass) {
            return $pendingAlerts->shift();
        }

        $index = $pendingAlerts->search(fn (TelegramAlert $alert): bool => $alert->class_booking_id === $booking->id);

        if ($index === false) {
            return $pendingAlerts->shift();
        }

        return $pendingAlerts->pull($index);
    }

    private function retargetPendingAlert(
        TelegramAlert $alert,
        ClassBooking $booking,
        ScheduledClass $scheduledClass,
        ScheduledClassTrainerChange $change,
    ): void {
        $payload = $this->queueAlert->payloadFor($scheduledClass, $booking);

        $alert->forceFill([
            'trainer_id' => $scheduledClass->trainer_id,
            'class_booking_id' => $booking->id,
            'dedupe_key' => $this->queueAlert->dedupeKeyFor($scheduledClass, 'trainer-change:'.$change->id),
            'text' => $this->renderer->render($scheduledClass->account, $payload),
            'payload' => $payload,
            'attempts' => 0,
            'next_attempt_at' => null,
            'failed_at' => null,
            'last_error' => null,
        ])->save();
    }
}
