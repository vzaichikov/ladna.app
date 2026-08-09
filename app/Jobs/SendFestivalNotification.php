<?php

namespace App\Jobs;

use App\Enums\FestivalNotificationStatus;
use App\Mail\FestivalPortalMail;
use App\Models\Account;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendFestivalNotification implements ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $notification = FestivalNotification::query()->whereKey($this->notificationId)->first();

        if (! $notification || $notification->status === FestivalNotificationStatus::Sent || $notification->status === FestivalNotificationStatus::Cancelled) {
            return;
        }

        $account = Account::active()->whereKey($notification->account_id)->where('enable_festivals', true)->first();
        $portalUser = FestivalPortalUser::query()->whereKey($notification->festival_portal_user_id)->where('account_id', $notification->account_id)->first();
        if (! $account || ! $portalUser || $portalUser->email !== $notification->recipient_email) {
            $notification->forceFill(['status' => FestivalNotificationStatus::Cancelled, 'cancelled_at' => now(), 'failure_reason' => 'recipient_state_changed'])->save();

            return;
        }

        $notification->forceFill(['status' => FestivalNotificationStatus::Sending, 'attempts' => $notification->attempts + 1])->save();

        try {
            $payload = $notification->payload;
            Mail::to($notification->recipient_email)->send(new FestivalPortalMail(
                subjectLine: (string) ($payload['subject'] ?? __('app.festival_notification_subject', locale: $portalUser->locale)),
                greeting: (string) ($payload['greeting'] ?? __('app.festival_notification_greeting', ['name' => $portalUser->displayName()], $portalUser->locale)),
                lines: array_values(array_map('strval', (array) ($payload['lines'] ?? [__('app.festival_notification_copy', locale: $portalUser->locale)]))),
                actionLabel: isset($payload['action_label']) ? (string) $payload['action_label'] : null,
                actionUrl: isset($payload['action_url']) ? (string) $payload['action_url'] : null,
                messageLocale: $portalUser->locale,
            ));
            $notification->forceFill(['status' => FestivalNotificationStatus::Sent, 'sent_at' => now(), 'failure_reason' => null])->save();
        } catch (Throwable $exception) {
            $notification->forceFill(['status' => FestivalNotificationStatus::Failed, 'failed_at' => now(), 'failure_reason' => str($exception->getMessage())->limit(1000)])->save();
            throw $exception;
        }
    }
}
