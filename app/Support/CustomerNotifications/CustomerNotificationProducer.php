<?php

namespace App\Support\CustomerNotifications;

use App\Enums\ClassBookingStatus;
use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationRecipientKind;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\CustomerNotification;
use App\Models\CustomerNotificationSetting;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CustomerNotificationProducer
{
    public function __construct(
        private readonly CustomerAuthAvailability $availability,
        private readonly CustomerNotificationSchedulePlanner $planner,
        private readonly CustomerNotificationTextRenderer $renderer,
        private readonly PhoneNumberNormalizer $phones,
    ) {}

    public function queueClassReminder(ClassBooking $booking): ?CustomerNotification
    {
        $booking->loadMissing([
            'account.customerAuthSetting',
            'account.customerNotificationSetting',
            'customer',
            'scheduledClass.account.customerAuthSetting',
            'scheduledClass.account.customerNotificationSetting',
            'scheduledClass.location',
            'scheduledClass.classType',
        ]);

        $scheduledClass = $booking->scheduledClass;
        $account = $booking->account ?? $scheduledClass?->account;

        if (! $account || ! $scheduledClass || ! $booking->customer) {
            $this->cancelClassReminder($booking, 'booking_context_missing');

            return null;
        }

        if (! $this->bookingIsActiveForClassReminder($booking, $scheduledClass)) {
            $this->cancelClassReminder($booking, 'booking_not_active');

            return null;
        }

        if (! $this->settingsAreEnabled($account)) {
            $this->cancelClassReminder($booking, 'customer_notifications_disabled');

            return null;
        }

        $notificationSetting = $this->notificationSettingFor($account);
        $scheduledSendAt = $this->planner->scheduledSendAt(
            $scheduledClass,
            $notificationSetting->class_reminder_hours_before,
        );

        if (! $scheduledSendAt) {
            $this->cancelClassReminder($booking, 'class_reminder_send_window_missing');

            return null;
        }

        $recipientPhone = $this->phones->normalize($booking->customer->phone, $account->country_code ?? 'UA');

        if (! $this->phones->isValid($recipientPhone, $account->country_code ?? 'UA')) {
            $this->cancelClassReminder($booking, 'customer_phone_invalid');

            return null;
        }

        $authSettings = $this->availability->settingsFor($account);
        $smsSetting = $this->availability->customerSmsSettingFor($account, $authSettings);

        if (! $smsSetting) {
            $this->cancelClassReminder($booking, 'customer_sms_not_configured');

            return null;
        }

        $attributes = [
            'account_id' => $account->id,
            'customer_id' => $booking->customer_id,
            'scheduled_class_id' => $scheduledClass->id,
            'class_booking_id' => $booking->id,
            'channel' => CustomerNotificationChannel::Sms->value,
            'type' => CustomerNotificationType::ClassReminder->value,
            'status' => CustomerNotificationStatus::Pending->value,
            'recipient_kind' => CustomerNotificationRecipientKind::Customer->value,
            'recipient_name' => $booking->customer->name,
            'recipient_phone' => $recipientPhone,
            'text' => $this->renderer->renderClassReminder($account, $scheduledClass, $booking->customer),
            'payload' => [
                'account_id' => $account->id,
                'customer_id' => $booking->customer_id,
                'scheduled_class_id' => $scheduledClass->id,
                'class_booking_id' => $booking->id,
                'class_title' => $scheduledClass->displayTitle(),
                'timezone' => $scheduledClass->displayTimezone(),
                'starts_at' => $scheduledClass->starts_at?->toIso8601String(),
                'reminder_hours_before' => $notificationSetting->class_reminder_hours_before,
            ],
            'provider_scope' => $authSettings->sms_sending_mode->value,
            'provider' => $smsSetting->provider->value,
            'provider_message_id' => null,
            'attempts' => 0,
            'scheduled_send_at' => $scheduledSendAt,
            'next_attempt_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'skipped_at' => null,
            'last_error' => null,
        ];

        $notification = CustomerNotification::query()
            ->where('dedupe_key', $this->classReminderDedupeKey($booking))
            ->first();

        if ($notification?->status === CustomerNotificationStatus::Sent) {
            return $notification;
        }

        $notification ??= new CustomerNotification([
            'dedupe_key' => $this->classReminderDedupeKey($booking),
        ]);

        $notification->fill($attributes);
        $notification->save();

        return $notification;
    }

    public function cancelClassReminder(ClassBooking $booking, string $reason = 'booking_cancelled'): int
    {
        if (! $booking->getKey()) {
            return 0;
        }

        return CustomerNotification::query()
            ->where('type', CustomerNotificationType::ClassReminder->value)
            ->where(function (Builder $query) use ($booking): void {
                $query->where('class_booking_id', $booking->id)
                    ->orWhere('dedupe_key', $this->classReminderDedupeKey($booking));
            })
            ->whereIn('status', [
                CustomerNotificationStatus::Pending->value,
                CustomerNotificationStatus::Processing->value,
                CustomerNotificationStatus::Failed->value,
            ])
            ->update([
                'status' => CustomerNotificationStatus::Cancelled->value,
                'next_attempt_at' => null,
                'cancelled_at' => now(),
                'last_error' => Str::limit($reason, 2000),
            ]);
    }

    public function queueClassCancellations(ScheduledClassCancellation $cancellation): int
    {
        $cancellation->loadMissing([
            'account.customerAuthSetting',
            'account.customerNotificationSetting',
            'effects.classBooking.customer',
            'scheduledClass.account.customerAuthSetting',
            'scheduledClass.account.customerNotificationSetting',
            'scheduledClass.location',
            'scheduledClass.classType',
        ]);

        if ($cancellation->isClosedCorrection()) {
            return 0;
        }

        $scheduledClass = $cancellation->scheduledClass;
        $account = $cancellation->account ?? $scheduledClass?->account;

        if (! $account || ! $scheduledClass || ! $this->classCancellationSettingsAreEnabled($account)) {
            return 0;
        }

        $authSettings = $this->availability->settingsFor($account);
        $smsSetting = $this->availability->customerSmsSettingFor($account, $authSettings);
        $queued = 0;

        foreach ($cancellation->effects as $effect) {
            $booking = $effect->classBooking;
            $customer = $booking?->customer;

            if (! $booking || ! $customer) {
                continue;
            }

            $recipientPhone = $this->phones->normalize($customer->phone, $account->country_code ?? 'UA');

            if (! $this->phones->isValid($recipientPhone, $account->country_code ?? 'UA')) {
                continue;
            }

            $dedupeKey = $this->classCancellationDedupeKey($cancellation, $booking);
            $notification = CustomerNotification::query()
                ->where('dedupe_key', $dedupeKey)
                ->first();

            if ($notification?->status === CustomerNotificationStatus::Sent) {
                continue;
            }

            $notification ??= new CustomerNotification(['dedupe_key' => $dedupeKey]);
            $notification->fill([
                'account_id' => $account->id,
                'customer_id' => $customer->id,
                'scheduled_class_id' => $scheduledClass->id,
                'class_booking_id' => $booking->id,
                'channel' => CustomerNotificationChannel::Sms->value,
                'type' => CustomerNotificationType::ClassCancellation->value,
                'status' => CustomerNotificationStatus::Pending->value,
                'recipient_kind' => CustomerNotificationRecipientKind::Customer->value,
                'recipient_name' => $customer->name,
                'recipient_phone' => $recipientPhone,
                'text' => $this->renderer->renderClassCancellation($account, $scheduledClass, $customer),
                'payload' => [
                    'account_id' => $account->id,
                    'customer_id' => $customer->id,
                    'scheduled_class_id' => $scheduledClass->id,
                    'class_booking_id' => $booking->id,
                    'scheduled_class_cancellation_id' => $cancellation->id,
                    'class_title' => $scheduledClass->displayTitle(),
                    'timezone' => $scheduledClass->displayTimezone(),
                    'starts_at' => $scheduledClass->starts_at?->toIso8601String(),
                ],
                'provider_scope' => $authSettings->sms_sending_mode->value,
                'provider' => $smsSetting?->provider->value,
                'provider_message_id' => null,
                'attempts' => 0,
                'scheduled_send_at' => now(),
                'next_attempt_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
                'skipped_at' => null,
                'last_error' => null,
            ]);
            $notification->save();
            $queued++;
        }

        return $queued;
    }

    public function cancelClassCancellations(ScheduledClassCancellation $cancellation, string $reason = 'scheduled_class_restored'): int
    {
        return CustomerNotification::query()
            ->where('type', CustomerNotificationType::ClassCancellation->value)
            ->where('dedupe_key', 'like', 'class_cancellation:cancellation:'.$cancellation->id.':%')
            ->whereIn('status', [
                CustomerNotificationStatus::Pending->value,
                CustomerNotificationStatus::Processing->value,
                CustomerNotificationStatus::Failed->value,
            ])
            ->update([
                'status' => CustomerNotificationStatus::Cancelled->value,
                'next_attempt_at' => null,
                'cancelled_at' => now(),
                'last_error' => Str::limit($reason, 2000),
            ]);
    }

    public function cancelClassRemindersForScheduledClass(ScheduledClass $scheduledClass, string $reason = 'scheduled_class_cancelled'): int
    {
        return CustomerNotification::query()
            ->where('type', CustomerNotificationType::ClassReminder->value)
            ->where('scheduled_class_id', $scheduledClass->id)
            ->whereIn('status', [
                CustomerNotificationStatus::Pending->value,
                CustomerNotificationStatus::Processing->value,
                CustomerNotificationStatus::Failed->value,
            ])
            ->update([
                'status' => CustomerNotificationStatus::Cancelled->value,
                'next_attempt_at' => null,
                'cancelled_at' => now(),
                'last_error' => Str::limit($reason, 2000),
            ]);
    }

    public function queueClassRemindersForScheduledClass(ScheduledClass $scheduledClass): int
    {
        $scheduledClass->loadMissing([
            'account.customerAuthSetting',
            'account.customerNotificationSetting',
            'location',
            'classBookings.customer',
        ]);

        $queued = 0;

        foreach ($scheduledClass->classBookings as $booking) {
            if ($this->queueClassReminder($booking)) {
                $queued++;
            }
        }

        return $queued;
    }

    public function bookingIsActiveForClassReminder(ClassBooking $booking, ?ScheduledClass $scheduledClass = null): bool
    {
        $scheduledClass ??= $booking->scheduledClass;

        return $scheduledClass !== null
            && $scheduledClass->status === ScheduledClassStatus::Scheduled
            && $scheduledClass->starts_at->isFuture()
            && ! $booking->isCorrectedRemoved()
            && in_array($booking->status, [ClassBookingStatus::Booked, ClassBookingStatus::Attended], true);
    }

    public function settingsAreEnabled(Account $account): bool
    {
        $setting = $this->notificationSettingFor($account);

        return ! $account->isReadOnlyDemo()
            && $account->customerNotificationsEnabled()
            && $setting->is_enabled
            && $setting->class_reminder_enabled;
    }

    public function classCancellationSettingsAreEnabled(Account $account): bool
    {
        $setting = $this->notificationSettingFor($account);

        return ! $account->isReadOnlyDemo()
            && $account->customerNotificationsEnabled()
            && $setting->is_enabled
            && $setting->class_cancellation_enabled;
    }

    public function notificationSettingFor(Account $account): CustomerNotificationSetting
    {
        $setting = $account->relationLoaded('customerNotificationSetting')
            ? $account->getRelation('customerNotificationSetting')
            : $account->customerNotificationSetting()->first();

        return $setting ?: new CustomerNotificationSetting([
            'account_id' => $account->id,
        ]);
    }

    public function classReminderDedupeKey(ClassBooking $booking): string
    {
        return 'class_reminder:booking:'.$booking->id;
    }

    public function classCancellationDedupeKey(ScheduledClassCancellation $cancellation, ClassBooking $booking): string
    {
        return 'class_cancellation:cancellation:'.$cancellation->id.':booking:'.$booking->id;
    }

    /**
     * @param  array<int, string>  $statuses
     */
    public function classReminderQuery(array $statuses): Builder
    {
        return CustomerNotification::query()
            ->where('type', CustomerNotificationType::ClassReminder->value)
            ->whereIn('status', $statuses);
    }
}
