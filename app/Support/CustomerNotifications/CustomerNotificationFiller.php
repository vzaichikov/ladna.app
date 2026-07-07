<?php

namespace App\Support\CustomerNotifications;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\ScheduledClassStatus;
use App\Models\CustomerNotification;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CustomerNotificationFiller
{
    public function __construct(
        private readonly CustomerNotificationProducer $producer,
    ) {}

    /**
     * @return array{processed: int, queued: int, cancelled: int, skipped: int}
     */
    public function fill(int $lookaheadHours = 192, int $limit = 1000): array
    {
        $lookaheadHours = max(1, min(720, $lookaheadHours));
        $limit = max(1, min(5000, $limit));
        $now = now();
        $lookahead = $now->copy()->addHours($lookaheadHours);

        $results = [
            'processed' => 0,
            'queued' => 0,
            'cancelled' => 0,
            'skipped' => 0,
        ];

        $classes = ScheduledClass::query()
            ->with([
                'account.customerAuthSetting',
                'account.customerNotificationSetting',
                'location',
                'classType',
                'classBookings.customer',
            ])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->whereBetween('starts_at', [$now, $lookahead])
            ->whereHas('account', fn (Builder $query): Builder => $query
                ->where('enable_customer_notifications', true)
                ->whereHas('customerNotificationSetting', fn (Builder $query): Builder => $query
                    ->where('is_enabled', true)
                    ->where('class_reminder_enabled', true)))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($classes as $scheduledClass) {
            foreach ($scheduledClass->classBookings as $booking) {
                $results['processed']++;

                if (! $this->isBookingStatusActive($this->bookingStatusValue($booking->status)) || $booking->isCorrectedRemoved()) {
                    $results['cancelled'] += $this->producer->cancelClassReminder($booking, 'booking_not_active');

                    continue;
                }

                if ($this->producer->queueClassReminder($booking)) {
                    $results['queued']++;
                } else {
                    $results['skipped']++;
                }
            }
        }

        $this->cancelStaleNotifications($lookahead, $limit, $results);

        return $results;
    }

    /**
     * @param  array{processed: int, queued: int, cancelled: int, skipped: int}  $results
     */
    private function cancelStaleNotifications(Carbon $lookahead, int $limit, array &$results): void
    {
        $notifications = CustomerNotification::query()
            ->with([
                'classBooking.account.customerAuthSetting',
                'classBooking.account.customerNotificationSetting',
                'classBooking.customer',
                'classBooking.scheduledClass.account.customerAuthSetting',
                'classBooking.scheduledClass.account.customerNotificationSetting',
                'classBooking.scheduledClass.location',
                'classBooking.scheduledClass.classType',
            ])
            ->where('type', CustomerNotificationType::ClassReminder->value)
            ->whereIn('status', [
                CustomerNotificationStatus::Pending->value,
                CustomerNotificationStatus::Processing->value,
                CustomerNotificationStatus::Failed->value,
            ])
            ->where('scheduled_send_at', '<=', $lookahead)
            ->orderBy('scheduled_send_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($notifications as $notification) {
            $results['processed']++;

            if (! $notification->classBooking) {
                $notification->forceFill([
                    'status' => CustomerNotificationStatus::Cancelled->value,
                    'next_attempt_at' => null,
                    'cancelled_at' => now(),
                    'last_error' => 'class_booking_missing',
                ])->save();

                $results['cancelled']++;

                continue;
            }

            if ($this->producer->queueClassReminder($notification->classBooking)) {
                continue;
            }

            $results['skipped']++;
        }
    }

    private function isBookingStatusActive(string $status): bool
    {
        return in_array($status, [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ], true);
    }

    private function bookingStatusValue(mixed $status): string
    {
        return $status instanceof ClassBookingStatus ? $status->value : (string) $status;
    }
}
