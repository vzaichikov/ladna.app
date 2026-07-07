<?php

namespace App\Support\CustomerNotifications;

use App\Models\ClassBooking;
use App\Models\ScheduledClassCancellation;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Telegram\Alerts\QueueTrainerAssignmentTelegramAlert;

class ClassBookingNotificationCoordinator
{
    public function __construct(
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly QueueTrainerAssignmentTelegramAlert $queueTrainerAssignmentTelegramAlert,
        private readonly CustomerNotificationProducer $customerNotifications,
    ) {}

    public function bookingCreated(ClassBooking $booking): void
    {
        $this->mailDispatcher->bookingCreated($booking);
        $this->queueTrainerAssignmentTelegramAlert->execute($booking);
        $this->customerNotifications->queueClassReminder($booking);
    }

    public function bookingCancelled(ClassBooking $booking): void
    {
        $this->mailDispatcher->bookingCancelled($booking);
        $this->customerNotifications->cancelClassReminder($booking);
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
        }
    }

    public function classRestored(ScheduledClassCancellation $cancellation): void
    {
        if ($cancellation->scheduledClass) {
            $this->customerNotifications->queueClassRemindersForScheduledClass($cancellation->scheduledClass);
        }
    }
}
