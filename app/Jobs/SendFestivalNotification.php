<?php

namespace App\Jobs;

use App\Actions\Festivals\FestivalEntrancePassEligibility;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalEntrancePassStatus;
use App\Enums\FestivalNotificationChannel;
use App\Enums\FestivalNotificationStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\SmsDeliveryPurpose;
use App\Mail\FestivalPortalMail;
use App\Models\Account;
use App\Models\FestivalEntrancePass;
use App\Models\FestivalNotification;
use App\Models\FestivalNotificationSetting;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicketOrder;
use App\Support\Mail\MailDeliverySettingsResolver;
use App\Support\PhoneNumberNormalizer;
use App\Support\Sms\SmsAutoTopUpService;
use App\Support\Sms\StudioSmsSender;
use App\Support\Telegram\FestivalTelegramNotificationSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendFestivalNotification implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $notificationId) {}

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }

    public function handle(
        StudioSmsSender $smsSender,
        SmsAutoTopUpService $autoTopUp,
        PhoneNumberNormalizer $phones,
        MailDeliverySettingsResolver $mailSettingsResolver,
        FestivalTelegramNotificationSender $telegramSender,
        FestivalEntrancePassEligibility $entrancePassEligibility,
    ): void {
        $notification = FestivalNotification::query()->whereKey($this->notificationId)->first();

        if (! $notification || in_array($notification->status, [FestivalNotificationStatus::Sent, FestivalNotificationStatus::Cancelled, FestivalNotificationStatus::WaitingForSmsCredit], true)) {
            return;
        }

        $account = Account::active()->whereKey($notification->account_id)->where('enable_festivals', true)->first();
        if (! $account) {
            $this->cancel($notification, 'account_state_changed');

            return;
        }

        if ($account->isReadOnlyDemo()) {
            $this->cancel($notification, 'read_only_demo');

            return;
        }

        $locale = $this->recipientLocale($notification);
        if ($locale === null) {
            $this->cancel($notification, 'recipient_state_changed');

            return;
        }

        if ($notification->type === FestivalNotificationType::EntrancePassesIssued
            && ! $this->entrancePassIsStillAvailable($notification, $entrancePassEligibility)) {
            $this->cancel($notification, 'entrance_pass_eligibility_changed');

            return;
        }

        if ($notification->channel === FestivalNotificationChannel::Sms && ! $this->smsStillEnabled($notification)) {
            $this->cancel($notification, 'festival_sms_scenario_disabled');

            return;
        }

        if ($notification->channel === FestivalNotificationChannel::Telegram && ! $this->telegramStillEnabled($notification)) {
            $this->cancel($notification, 'festival_telegram_scenario_disabled');

            return;
        }

        $claimed = FestivalNotification::query()
            ->whereKey($notification->id)
            ->whereIn('status', [FestivalNotificationStatus::Pending->value, FestivalNotificationStatus::Failed->value])
            ->where('attempts', '<', $this->tries)
            ->update([
                'status' => FestivalNotificationStatus::Sending->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return;
        }
        $notification->refresh();

        try {
            if ($notification->channel === FestivalNotificationChannel::Sms) {
                $this->sendSms($notification, $account, $smsSender, $autoTopUp, $phones);

                return;
            }

            if ($notification->channel === FestivalNotificationChannel::Telegram) {
                $result = $telegramSender->send($notification, $account);

                if ($result['sent']) {
                    $this->markSent($notification);
                } else {
                    $this->cancel($notification, (string) $result['cancel_reason']);
                }

                return;
            }

            $payload = $notification->payload;
            $actionLabel = isset($payload['action_label']) ? (string) $payload['action_label'] : null;
            $actionUrl = isset($payload['action_url']) ? (string) $payload['action_url'] : null;
            if ($notification->festival_ticket_order_id) {
                $order = FestivalTicketOrder::query()
                    ->whereKey($notification->festival_ticket_order_id)
                    ->where('account_id', $notification->account_id)
                    ->where('festival_edition_id', $notification->festival_edition_id)
                    ->where('status', FestivalTicketOrderStatus::Paid->value)
                    ->where('buyer_email', $notification->recipient_email)
                    ->first();
                if (! $order) {
                    $this->cancel($notification, 'recipient_state_changed');

                    return;
                }
                $actionLabel = __('app.festival_open_tickets', locale: $locale);
                $actionUrl = route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);
            }
            $mailSettings = $mailSettingsResolver->resolve();
            $mail = (new FestivalPortalMail(
                subjectLine: (string) ($notification->subject ?? __('app.festival_notification_subject', locale: $locale)),
                greeting: (string) ($payload['greeting'] ?? __('app.festival_notification_greeting', ['name' => $notification->recipient_name], $locale)),
                lines: array_values(array_map('strval', (array) ($payload['lines'] ?? [$notification->text]))),
                actionLabel: $actionLabel,
                actionUrl: $actionUrl,
                messageLocale: $locale,
            ))->from($mailSettings->fromEmail, $mailSettings->fromName);

            Mail::mailer($mailSettings->mailer)
                ->to($notification->recipient_email, $notification->recipient_name ?: $notification->recipient_email)
                ->send($mail);
            $this->markSent($notification);
        } catch (Throwable $exception) {
            $notification->forceFill(['status' => FestivalNotificationStatus::Failed, 'failed_at' => now(), 'failure_reason' => str($exception->getMessage())->limit(1000)])->save();
            throw $exception;
        }
    }

    private function entrancePassIsStillAvailable(FestivalNotification $notification, FestivalEntrancePassEligibility $eligibility): bool
    {
        $passes = FestivalEntrancePass::query()
            ->where('account_id', $notification->account_id)
            ->where('festival_edition_id', $notification->festival_edition_id)
            ->where('status', FestivalEntrancePassStatus::Valid->value)
            ->whereHas('participant', fn ($query) => $query
                ->where('festival_portal_user_id', $notification->festival_portal_user_id))
            ->with(['edition', 'participant'])
            ->get();

        return $passes->contains(fn (FestivalEntrancePass $pass): bool => ! in_array($pass->edition->status, [FestivalEditionStatus::Cancelled, FestivalEditionStatus::Archived], true)
            && ! $pass->edition->ends_at?->isPast()
            && $eligibility->isEligible($pass->edition, $pass->participant));
    }

    public function failed(?Throwable $exception): void
    {
        $notification = FestivalNotification::query()->whereKey($this->notificationId)->first();
        if (! $notification || in_array($notification->status, [FestivalNotificationStatus::Sent, FestivalNotificationStatus::Cancelled, FestivalNotificationStatus::WaitingForSmsCredit], true)) {
            return;
        }

        $notification->forceFill([
            'status' => FestivalNotificationStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => str($exception?->getMessage() ?: 'festival_notification_delivery_failed')->limit(1000),
        ])->save();
    }

    private function sendSms(FestivalNotification $notification, Account $account, StudioSmsSender $smsSender, SmsAutoTopUpService $autoTopUp, PhoneNumberNormalizer $phones): void
    {
        $phone = $phones->normalize($notification->recipient_phone, $account->country_code ?? 'UA');
        if (! $phones->isValid($phone, $account->country_code ?? 'UA')) {
            $this->cancel($notification, 'festival_recipient_phone_missing_or_invalid');

            return;
        }

        $result = $smsSender->send(
            account: $account,
            phone: $phone,
            message: (string) $notification->text,
            purpose: SmsDeliveryPurpose::FestivalNotification,
            source: $notification,
            idempotencyKey: 'festival-notification:'.$notification->id.':attempt:'.$notification->attempts,
        );

        if ($result->waitingForCredit()) {
            $notification->forceFill([
                'status' => FestivalNotificationStatus::WaitingForSmsCredit,
                'failure_reason' => 'waiting_for_sms_credit',
                'failed_at' => null,
            ])->save();
            $autoTopUp->attempt($account);

            return;
        }

        if ($result->unknown()) {
            $notification->forceFill([
                'status' => FestivalNotificationStatus::Failed,
                'failure_reason' => 'sms_delivery_outcome_unknown',
                'failed_at' => now(),
            ])->save();

            return;
        }

        if (! $result->accepted()) {
            $reason = $result->delivery->error_code ?: $result->message ?: 'festival_sms_send_failed';
            $notification->forceFill([
                'status' => FestivalNotificationStatus::Failed,
                'failure_reason' => $reason,
                'failed_at' => now(),
            ])->save();

            if (in_array($result->delivery->error_code, ['sms_provider_rejected'], true)) {
                throw new RuntimeException($reason);
            }

            return;
        }

        $this->markSent($notification);
    }

    private function recipientLocale(FestivalNotification $notification): ?string
    {
        if ($notification->festival_portal_user_id) {
            $portalUser = FestivalPortalUser::query()
                ->whereKey($notification->festival_portal_user_id)
                ->where('account_id', $notification->account_id)
                ->where('is_active', true)
                ->first();

            if (! $portalUser || ($notification->channel !== FestivalNotificationChannel::Telegram && $portalUser->email !== $notification->recipient_email)) {
                return null;
            }

            return $portalUser->locale;
        }

        if ($notification->festival_ticket_order_id) {
            $order = FestivalTicketOrder::query()
                ->whereKey($notification->festival_ticket_order_id)
                ->where('account_id', $notification->account_id)
                ->where('festival_edition_id', $notification->festival_edition_id)
                ->where('status', FestivalTicketOrderStatus::Paid->value)
                ->first();

            if (! $order || $order->buyer_email !== $notification->recipient_email) {
                return null;
            }

            return $order->locale;
        }

        return null;
    }

    private function smsStillEnabled(FestivalNotification $notification): bool
    {
        return FestivalNotificationSetting::query()
            ->where('account_id', $notification->account_id)
            ->where('type', $notification->type->value)
            ->where('send_sms', true)
            ->exists();
    }

    private function telegramStillEnabled(FestivalNotification $notification): bool
    {
        return FestivalNotificationSetting::query()
            ->where('account_id', $notification->account_id)
            ->where('type', $notification->type->value)
            ->value('send_telegram') ?? true;
    }

    private function markSent(FestivalNotification $notification): void
    {
        $notification->forceFill([
            'status' => FestivalNotificationStatus::Sent,
            'sent_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    private function cancel(FestivalNotification $notification, string $reason): void
    {
        $notification->forceFill([
            'status' => FestivalNotificationStatus::Cancelled,
            'cancelled_at' => now(),
            'failure_reason' => $reason,
        ])->save();
    }
}
