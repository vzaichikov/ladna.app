<?php

namespace App\Support\CustomerNotifications;

use App\Models\ClassBooking;
use App\Models\ScheduledClassCancellation;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Telegram\Alerts\QueueTrainerAssignmentTelegramAlert;
use App\Support\Telegram\Alerts\QueueTrainerClassCancellationTelegramAlert;

class ClassBookingNotificationCoordinator
{
    public function __construct(
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly QueueTrainerAssignmentTelegramAlert $queueTrainerAssignmentTelegramAlert,
        private readonly QueueTrainerClassCancellationTelegramAlert $queueTrainerClassCancellationTelegramAlert,
        private readonly CustomerNotificationProducer $customerNotifications,
    ) {}

    public function bookingCreated(ClassBooking $booking): void
    {
        $this->mailDispatcher->bookingCreated($booking);
        $this->queueTrainerAssignmentTelegramAlert->execute($booking);
        $this->customerNotifications->queueClassReminder($booking);
    }

    public function bookingCancelled(
        ClassBooking $booking,
        bool $wasActive = true,
        ?bool $becameEmpty = null,
        ?string $cancellationEventId = null,
    ): void {
        $this->mailDispatcher->bookingCancelled($booking);
        $this->customerNotifications->cancelClassReminder($booking);

        $scheduledClass = $booking->scheduledClass;
        $becameEmpty ??= $wasActive
            && $scheduledClass
            && ! $this->queueTrainerClassCancellationTelegramAlert->hasActiveBookings($scheduledClass);

        if ($becameEmpty && $scheduledClass) {
            $this->queueTrainerClassCancellationTelegramAlert->supersedePendingAssignmentAlerts(
                $scheduledClass,
                'all_bookings_cancelled',
            );
            $this->queueTrainerClassCancellationTelegramAlert->forAllBookingsCancelled(
                $scheduledClass,
                $booking,
                $cancellationEventId,
            );
        } elseif ($wasActive && $scheduledClass) {
            $this->queueTrainerClassCancellationTelegramAlert->syncPendingAssignmentAlertsAfterBookingCancellation(
                $scheduledClass,
                $booking,
            );
        }
    }

    public function bookingUpdatedToActive(ClassBooking $booking): void
    {
        $this->queueTrainerAssignmentTelegramAlert->execute($booking);
        $this->customerNotifications->queueClassReminder($booking);
    }

    public function bookingNoLongerActive(ClassBooking $booking, string $reason = 'booking_not_active'): void
    {
        $this->customerNotifications->cancelClassReminder($booking, $reason);
    }

    public function classCancelled(ScheduledClassCancellation $cancellation): void
    {
        if ($cancellation->scheduledClass) {
            $this->customerNotifications->cancelClassRemindersForScheduledClass($cancellation->scheduledClass, 'scheduled_class_cancelled');
            $this->queueTrainerClassCancellationTelegramAlert->supersedePendingAssignmentAlerts(
                $cancellation->scheduledClass,
                'scheduled_class_cancelled',
            );
        }

        $this->queueTrainerClassCancellationTelegramAlert->forStudioCancellation($cancellation);
        $this->customerNotifications->queueClassCancellations($cancellation);
    }

    public function classRestored(ScheduledClassCancellation $cancellation): void
    {
        if ($cancellation->scheduledClass) {
            $this->customerNotifications->cancelClassCancellations($cancellation);
            $this->queueTrainerClassCancellationTelegramAlert->supersedePendingCancellationAlerts(
                $cancellation,
                'scheduled_class_restored',
            );
            $this->customerNotifications->queueClassRemindersForScheduledClass($cancellation->scheduledClass);
        }
    }
}
