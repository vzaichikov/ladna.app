<?php

namespace App\Support\CustomerNotifications;

use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\ScheduledClassStatus;
use App\Models\CustomerNotification;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\SmsGatewayResolver;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CustomerNotificationSender
{
    private const MaxAttempts = 3;

    public function __construct(
        private readonly CustomerAuthAvailability $availability,
        private readonly SmsGatewayResolver $gateways,
        private readonly PhoneNumberNormalizer $phones,
        private readonly CustomerNotificationProducer $producer,
        private readonly CustomerNotificationSchedulePlanner $planner,
        private readonly CustomerNotificationTextRenderer $renderer,
    ) {}

    /**
     * @return array{processed: int, sent: int, retried: int, failed: int, cancelled: int, skipped: int, rescheduled: int}
     */
    public function sendPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $results = [
            'processed' => 0,
            'sent' => 0,
            'retried' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'skipped' => 0,
            'rescheduled' => 0,
        ];

        $notificationIds = CustomerNotification::query()
            ->whereHas('account', fn ($query) => $query->operational())
            ->where('status', CustomerNotificationStatus::Pending->value)
            ->whereNotNull('scheduled_send_at')
            ->where('scheduled_send_at', '<=', now())
            ->where(fn ($query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderByRaw('COALESCE(next_attempt_at, scheduled_send_at, created_at)')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($notificationIds as $notificationId) {
            $notification = $this->claim((int) $notificationId);

            if (! $notification) {
                continue;
            }

            $results['processed']++;
            $result = $this->send($notification);
            $results[$result]++;
        }

        return $results;
    }

    private function claim(int $notificationId): ?CustomerNotification
    {
        return DB::transaction(function () use ($notificationId): ?CustomerNotification {
            $notification = CustomerNotification::query()
                ->whereHas('account', fn ($query) => $query->operational())
                ->whereKey($notificationId)
                ->lockForUpdate()
                ->first();

            if (
                ! $notification
                || $notification->status !== CustomerNotificationStatus::Pending
                || ! $notification->scheduled_send_at
                || $notification->scheduled_send_at->isFuture()
                || ($notification->next_attempt_at && $notification->next_attempt_at->isFuture())
            ) {
                return null;
            }

            $notification->forceFill([
                'status' => CustomerNotificationStatus::Processing->value,
                'attempts' => $notification->attempts + 1,
            ])->save();

            return $notification->refresh();
        });
    }

    private function send(CustomerNotification $notification): string
    {
        $notification->loadMissing([
            'account.customerAuthSetting',
            'account.customerNotificationSetting',
            'customer',
            'classBooking.customer',
            'classBooking.scheduledClass.account.customerAuthSetting',
            'classBooking.scheduledClass.account.customerNotificationSetting',
            'classBooking.scheduledClass.location',
            'classBooking.scheduledClass.classType',
            'scheduledClass.account.customerAuthSetting',
            'scheduledClass.account.customerNotificationSetting',
            'scheduledClass.location',
            'scheduledClass.classType',
        ]);

        if ($notification->channel !== CustomerNotificationChannel::Sms) {
            return $this->skip($notification, 'unsupported_customer_notification_channel');
        }

        if ($notification->type !== CustomerNotificationType::ClassReminder) {
            return $this->skip($notification, 'unsupported_customer_notification_type');
        }

        $booking = $notification->classBooking;
        $scheduledClass = $booking?->scheduledClass ?? $notification->scheduledClass;
        $account = $booking?->account ?? $scheduledClass?->account ?? $notification->account;
        $customer = $booking?->customer ?? $notification->customer;

        if (! $booking || ! $scheduledClass || ! $account || ! $customer) {
            return $this->cancel($notification, 'customer_notification_context_missing');
        }

        if ($account->isReadOnlyDemo()) {
            return $this->cancel($notification, 'read_only_demo');
        }

        if (! $this->producer->bookingIsActiveForClassReminder($booking, $scheduledClass)) {
            return $this->cancel($notification, 'booking_not_active');
        }

        if (! $this->producer->settingsAreEnabled($account)) {
            return $this->cancel($notification, 'customer_notifications_disabled');
        }

        if (! $this->planner->isAllowedSendTime($scheduledClass)) {
            $nextAllowed = $this->planner->nextAllowedSendAt($scheduledClass);

            if (! $nextAllowed) {
                return $this->cancel($notification, 'customer_notification_quiet_hours_window_expired');
            }

            $notification->forceFill([
                'status' => CustomerNotificationStatus::Pending->value,
                'scheduled_send_at' => $nextAllowed,
                'next_attempt_at' => null,
                'last_error' => 'rescheduled_after_quiet_hours',
            ])->save();

            return 'rescheduled';
        }

        if ($scheduledClass->status !== ScheduledClassStatus::Scheduled || $scheduledClass->starts_at->isPast()) {
            return $this->cancel($notification, 'scheduled_class_not_sendable');
        }

        $phone = $this->phones->normalize($notification->recipient_phone ?: $customer->phone, $account->country_code ?? 'UA');

        if (! $this->phones->isValid($phone, $account->country_code ?? 'UA')) {
            return $this->skip($notification, 'customer_phone_invalid');
        }

        $authSettings = $this->availability->settingsFor($account);
        $smsSetting = $this->availability->customerSmsSettingFor($account, $authSettings);

        if (! $smsSetting) {
            return $this->retryOrFail($notification, 'customer_sms_not_configured');
        }

        $text = (string) ($notification->text ?: $this->renderer->renderClassReminder($account, $scheduledClass, $customer));

        try {
            $result = $this->gateways->resolve($smsSetting)->sendSms($phone, $text);
        } catch (Throwable $exception) {
            return $this->retryOrFail($notification, $exception->getMessage() ?: 'customer_sms_send_failed');
        }

        if (! $result->sent) {
            return $this->retryOrFail($notification, $result->message ?: 'customer_sms_send_failed');
        }

        $notification->forceFill([
            'status' => CustomerNotificationStatus::Sent->value,
            'recipient_phone' => $phone,
            'text' => $text,
            'provider_scope' => $authSettings->customer_sms_sender_scope->value,
            'provider' => $smsSetting->provider->value,
            'provider_message_id' => $result->providerMessageId,
            'next_attempt_at' => null,
            'sent_at' => now(),
            'failed_at' => null,
            'cancelled_at' => null,
            'skipped_at' => null,
            'last_error' => null,
        ])->save();

        return 'sent';
    }

    private function retryOrFail(CustomerNotification $notification, string $error): string
    {
        $failed = $notification->attempts >= self::MaxAttempts;

        $notification->forceFill([
            'status' => $failed ? CustomerNotificationStatus::Failed->value : CustomerNotificationStatus::Pending->value,
            'next_attempt_at' => $failed ? null : now()->addMinutes($this->backoffMinutes($notification->attempts)),
            'failed_at' => $failed ? now() : null,
            'last_error' => Str::limit($error, 2000),
        ])->save();

        return $failed ? 'failed' : 'retried';
    }

    private function cancel(CustomerNotification $notification, string $reason): string
    {
        $notification->forceFill([
            'status' => CustomerNotificationStatus::Cancelled->value,
            'next_attempt_at' => null,
            'cancelled_at' => now(),
            'last_error' => Str::limit($reason, 2000),
        ])->save();

        return 'cancelled';
    }

    private function skip(CustomerNotification $notification, string $reason): string
    {
        $notification->forceFill([
            'status' => CustomerNotificationStatus::Skipped->value,
            'next_attempt_at' => null,
            'skipped_at' => now(),
            'last_error' => Str::limit($reason, 2000),
        ])->save();

        return 'skipped';
    }

    private function backoffMinutes(int $attempts): int
    {
        return match ($attempts) {
            1 => 1,
            2 => 5,
            default => 15,
        };
    }
}
