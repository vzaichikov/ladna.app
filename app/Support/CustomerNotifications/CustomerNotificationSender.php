<?php

namespace App\Support\CustomerNotifications;

use App\Enums\CustomerNotificationChannel;
use App\Enums\CustomerNotificationStatus;
use App\Enums\CustomerNotificationType;
use App\Enums\ScheduledClassStatus;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsSendingMode;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\ScheduledClassCancellation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramMessage;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\PhoneNumberNormalizer;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\StudioSmsSender;
use App\Support\Telegram\CustomerTelegramLinkResolver;
use App\Support\Telegram\TelegramClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CustomerNotificationSender
{
    private const MaxAttempts = 3;

    public function __construct(
        private readonly CustomerAuthAvailability $availability,
        private readonly StudioSmsSender $smsSender,
        private readonly SmsAutoTopUpService $autoTopUp,
        private readonly PhoneNumberNormalizer $phones,
        private readonly CustomerNotificationProducer $producer,
        private readonly CustomerNotificationSchedulePlanner $planner,
        private readonly CustomerNotificationTextRenderer $renderer,
        private readonly CustomerTelegramLinkResolver $telegramLinks,
        private readonly TelegramClient $telegramClient,
    ) {}

    /**
     * @return array{processed: int, sent: int, waiting: int, retried: int, failed: int, cancelled: int, skipped: int, rescheduled: int}
     */
    public function sendPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $results = [
            'processed' => 0,
            'sent' => 0,
            'waiting' => 0,
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
            'telegramChatAuthorization.installation',
        ]);

        if (! in_array($notification->channel, CustomerNotificationChannel::cases(), true)) {
            return $this->skip($notification, 'unsupported_customer_notification_channel');
        }

        if (! in_array($notification->type, [
            CustomerNotificationType::ClassReminder,
            CustomerNotificationType::ClassCancellation,
        ], true)) {
            return $this->skip($notification, 'unsupported_customer_notification_type');
        }

        $booking = $notification->classBooking;
        $scheduledClass = $booking?->scheduledClass ?? $notification->scheduledClass;
        $account = $booking?->account ?? $scheduledClass?->account ?? $notification->account;
        $customer = $booking?->customer ?? $notification->customer;

        if (
            ! $scheduledClass
            || ! $account
            || ! $customer
            || ($notification->type === CustomerNotificationType::ClassReminder && ! $booking)
        ) {
            return $this->cancel($notification, 'customer_notification_context_missing');
        }

        if ($account->isReadOnlyDemo()) {
            return $this->cancel($notification, 'read_only_demo');
        }

        if ($notification->type === CustomerNotificationType::ClassReminder) {
            /** @var ClassBooking $booking */
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
        } else {
            if (! $this->producer->classCancellationSettingsAreEnabled($account)) {
                return $this->cancel($notification, 'customer_class_cancellation_notifications_disabled');
            }

            if ($scheduledClass->status !== ScheduledClassStatus::Cancelled) {
                return $this->cancel($notification, 'scheduled_class_not_cancelled');
            }

            $cancellationId = (int) data_get($notification->payload, 'scheduled_class_cancellation_id', 0);
            $activeCancellationExists = $cancellationId > 0
                && ScheduledClassCancellation::query()
                    ->whereKey($cancellationId)
                    ->where('scheduled_class_id', $scheduledClass->id)
                    ->whereNull('restored_at')
                    ->exists();

            if (! $activeCancellationExists) {
                return $this->cancel($notification, 'scheduled_class_cancellation_restored');
            }
        }

        $text = (string) ($notification->text ?: match ($notification->type) {
            CustomerNotificationType::ClassReminder => $this->renderer->renderClassReminder($account, $scheduledClass, $customer),
            CustomerNotificationType::ClassCancellation => $this->renderer->renderClassCancellation($account, $scheduledClass, $customer),
        });

        if ($this->deliveryChannel($notification, $account, $customer) === CustomerNotificationChannel::Telegram) {
            $telegramResult = $this->sendTelegram($notification, $account, $customer, $text);

            if ($telegramResult !== null) {
                return $telegramResult;
            }
        }

        return $this->sendSms($notification, $account, $customer, $text);
    }

    private function sendSms(CustomerNotification $notification, Account $account, Customer $customer, string $text): string
    {
        $phone = $this->phones->normalize($notification->recipient_phone ?: $customer->phone, $account->country_code ?? 'UA');

        if (! $this->phones->isValid($phone, $account->country_code ?? 'UA')) {
            return $this->skip($notification, 'customer_phone_invalid');
        }

        $authSettings = $this->availability->settingsFor($account);

        if ($authSettings->sms_sending_mode === SmsSendingMode::Disabled) {
            return $this->cancel($notification, 'customer_sms_disabled');
        }

        $currentStatus = $notification->fresh()->status;

        if ($currentStatus !== CustomerNotificationStatus::Processing) {
            return match ($currentStatus) {
                CustomerNotificationStatus::Cancelled => 'cancelled',
                CustomerNotificationStatus::Failed => 'failed',
                CustomerNotificationStatus::Skipped => 'skipped',
                default => 'cancelled',
            };
        }

        $sendResult = $this->smsSender->send(
            account: $account,
            phone: $phone,
            message: $text,
            purpose: SmsDeliveryPurpose::CustomerNotification,
            source: $notification,
            idempotencyKey: 'customer-notification:'.$notification->id.':attempt:'.$notification->attempts,
        );

        if ($sendResult->waitingForCredit()) {
            $notification->forceFill([
                'status' => CustomerNotificationStatus::WaitingForSmsCredit,
                'recipient_phone' => $phone,
                'text' => $text,
                'provider_scope' => $authSettings->sms_sending_mode->value,
                'provider' => $sendResult->delivery->provider,
                'next_attempt_at' => null,
                'last_error' => 'waiting_for_sms_credit',
            ])->save();

            $this->autoTopUp->attempt($account);

            return 'waiting';
        }

        if ($sendResult->unknown()) {
            $notification->forceFill([
                'status' => CustomerNotificationStatus::Failed,
                'recipient_phone' => $phone,
                'text' => $text,
                'provider_scope' => $authSettings->sms_sending_mode->value,
                'provider' => $sendResult->delivery->provider,
                'provider_message_id' => $sendResult->delivery->provider_message_id,
                'next_attempt_at' => null,
                'failed_at' => now(),
                'last_error' => 'sms_delivery_outcome_unknown',
            ])->save();

            return 'failed';
        }

        if (! $sendResult->accepted()) {
            return $this->retryOrFail($notification, $sendResult->message ?: 'customer_sms_send_failed');
        }

        $notification->forceFill([
            'status' => CustomerNotificationStatus::Sent->value,
            'recipient_phone' => $phone,
            'text' => $text,
            'provider_scope' => $authSettings->sms_sending_mode->value,
            'provider' => $sendResult->delivery->provider,
            'provider_message_id' => $sendResult->delivery->provider_message_id,
            'next_attempt_at' => null,
            'sent_at' => now(),
            'failed_at' => null,
            'cancelled_at' => null,
            'skipped_at' => null,
            'last_error' => null,
        ])->save();

        return 'sent';
    }

    private function deliveryChannel(CustomerNotification $notification, Account $account, Customer $customer): CustomerNotificationChannel
    {
        if ($notification->channel === CustomerNotificationChannel::Sms) {
            return CustomerNotificationChannel::Sms;
        }

        if ($notification->channel === CustomerNotificationChannel::Telegram) {
            return CustomerNotificationChannel::Telegram;
        }

        if ($notification->resolved_channel instanceof CustomerNotificationChannel) {
            return $notification->resolved_channel;
        }

        $authorization = $this->telegramLinks->activeAuthorization($account, $customer);
        $channel = $authorization ? CustomerNotificationChannel::Telegram : CustomerNotificationChannel::Sms;
        $notification->forceFill([
            'resolved_channel' => $channel->value,
            'telegram_chat_authorization_id' => $authorization?->id,
        ])->save();

        if ($authorization) {
            $notification->setRelation('telegramChatAuthorization', $authorization);
        }

        return $channel;
    }

    private function sendTelegram(CustomerNotification $notification, Account $account, Customer $customer, string $text): ?string
    {
        $authorization = $notification->telegramChatAuthorization
            ?? $this->telegramLinks->activeAuthorization($account, $customer);

        if (! $this->telegramAuthorizationIsCurrent($authorization, $account, $customer)) {
            $this->fallBackToSms($notification);

            return null;
        }

        try {
            $response = $this->telegramClient->sendMessage(
                $authorization->installation,
                $authorization->telegram_chat_id,
                $text,
                ['disable_web_page_preview' => true],
            );
        } catch (Throwable $throwable) {
            report(new RuntimeException('Customer Telegram notification delivery failed ('.$throwable::class.').'));

            return $this->retryOrFail($notification, 'telegram_customer_notification_failed');
        }

        if ($this->telegramOk($response)) {
            $messageId = filled($response?->json('result.message_id'))
                ? (string) $response?->json('result.message_id')
                : null;
            $notification->forceFill([
                'status' => CustomerNotificationStatus::Sent->value,
                'resolved_channel' => CustomerNotificationChannel::Telegram->value,
                'telegram_chat_authorization_id' => $authorization->id,
                'text' => $text,
                'provider_scope' => 'studio_bot',
                'provider' => 'telegram',
                'provider_message_id' => $messageId,
                'next_attempt_at' => null,
                'sent_at' => now(),
                'failed_at' => null,
                'cancelled_at' => null,
                'skipped_at' => null,
                'last_error' => null,
            ])->save();
            TelegramMessage::create([
                'account_id' => $account->id,
                'telegram_bot_installation_id' => $authorization->telegram_bot_installation_id,
                'telegram_chat_authorization_id' => $authorization->id,
                'profile' => $authorization->profile->value,
                'telegram_chat_id' => $authorization->telegram_chat_id,
                'telegram_message_id' => $messageId,
                'direction' => 'outbound',
                'message_type' => 'notification',
                'text' => $text,
                'payload' => ['customer_notification_id' => $notification->id],
                'sent_at' => now(),
            ]);

            return 'sent';
        }

        if ($this->telegramRecipientUnavailable($response)) {
            $authorization->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ])->save();
            $this->fallBackToSms($notification);

            return null;
        }

        return $this->retryOrFail(
            $notification,
            (string) ($response?->json('description') ?: 'telegram_customer_notification_failed'),
        );
    }

    private function telegramAuthorizationIsCurrent(?TelegramChatAuthorization $authorization, Account $account, Customer $customer): bool
    {
        if (! $authorization || $authorization->account_id !== $account->id || $authorization->customer_id !== $customer->id) {
            return false;
        }

        $authorization->loadMissing('installation');

        return $authorization->status === TelegramChatAuthorizationStatus::Authorized
            && $authorization->installation?->account_id === $account->id
            && $authorization->installation?->is_enabled
            && filled($authorization->installation?->tokenValue());
    }

    private function fallBackToSms(CustomerNotification $notification): void
    {
        $notification->forceFill([
            'resolved_channel' => CustomerNotificationChannel::Sms->value,
            'telegram_chat_authorization_id' => null,
            'fallback_used_at' => now(),
            'provider_scope' => null,
            'provider' => null,
            'provider_message_id' => null,
        ])->save();
        $notification->unsetRelation('telegramChatAuthorization');
    }

    private function telegramRecipientUnavailable(?Response $response): bool
    {
        $description = Str::lower((string) $response?->json('description', ''));

        return $response?->status() === 403
            || Str::contains($description, [
                'bot was blocked',
                'chat not found',
                'user is deactivated',
                "bot can't initiate conversation",
            ]);
    }

    private function telegramOk(?Response $response): bool
    {
        return $response?->successful() === true && $response->json('ok') === true;
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
