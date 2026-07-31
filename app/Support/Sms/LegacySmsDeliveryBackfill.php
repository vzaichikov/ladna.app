<?php

namespace App\Support\Sms;

use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\CustomerNotification;
use App\Models\CustomerOtpChallenge;
use App\Support\CustomerAuth\SmsSegmentCalculator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class LegacySmsDeliveryBackfill
{
    public function __construct(
        private readonly SmsSegmentCalculator $segments,
    ) {}

    /**
     * @return array{customer_notifications: int, customer_otp_sends: int}
     */
    public function run(?int $accountId = null): array
    {
        return [
            'customer_notifications' => $this->backfillCustomerNotifications($accountId),
            'customer_otp_sends' => $this->backfillCustomerOtpSends($accountId),
        ];
    }

    private function backfillCustomerNotifications(?int $accountId): int
    {
        $inserted = 0;

        DB::table('customer_notifications')
            ->where('channel', 'sms')
            ->whereIn('status', ['sent', 'failed'])
            ->whereNotNull('recipient_phone')
            ->when($accountId, fn (Builder $query): Builder => $query->where('account_id', $accountId))
            ->orderBy('id')
            ->chunkById(200, function (Collection $notifications) use (&$inserted): void {
                $rows = $notifications
                    ->map(fn (stdClass $notification): array => $this->customerNotificationRow($notification))
                    ->all();

                $inserted += DB::table('sms_deliveries')->insertOrIgnore($rows);
            });

        return $inserted;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerNotificationRow(stdClass $notification): array
    {
        $message = (string) ($notification->text ?? '');
        $segments = max(1, $this->segments->estimate($message)->segments);
        $accepted = $notification->status === 'sent';
        $occurredAt = $accepted
            ? ($notification->sent_at ?? $notification->updated_at ?? $notification->created_at)
            : ($notification->failed_at ?? $notification->updated_at ?? $notification->created_at);

        return [
            'account_id' => $notification->account_id,
            'source_type' => CustomerNotification::class,
            'source_id' => $notification->id,
            'purpose' => SmsDeliveryPurpose::CustomerNotification->value,
            'source_mode' => $this->sourceMode((string) ($notification->provider_scope ?? '')),
            'provider' => $notification->provider,
            'status' => $accepted ? SmsDeliveryStatus::Accepted->value : SmsDeliveryStatus::Failed->value,
            'recipient_phone' => $notification->recipient_phone,
            'message_preview' => $message === '' ? null : Str::limit($message, 255, ''),
            'idempotency_key' => 'legacy-customer-notification:'.$notification->id,
            'estimated_segments' => $segments,
            'billed_segments' => $segments,
            'reserved_amount_cents' => 0,
            'currency' => 'UAH',
            'provider_message_id' => $notification->provider_message_id,
            'accepted_at' => $accepted ? $notification->sent_at : null,
            'failed_at' => $accepted ? null : ($notification->failed_at ?? $occurredAt),
            'error_code' => $accepted ? null : 'legacy_customer_notification_failed',
            'last_error' => $accepted ? null : $this->sanitizedError($notification->last_error),
            'created_at' => $occurredAt,
            'updated_at' => $notification->updated_at ?? $occurredAt,
        ];
    }

    private function backfillCustomerOtpSends(?int $accountId): int
    {
        $inserted = 0;

        DB::table('customer_otp_challenges')
            ->where('send_count', '>', 0)
            ->whereNotNull('last_sent_at')
            ->when($accountId, fn (Builder $query): Builder => $query->where('account_id', $accountId))
            ->orderBy('id')
            ->chunkById(200, function (Collection $challenges) use (&$inserted): void {
                $rows = [];

                foreach ($challenges as $challenge) {
                    for ($sendIndex = 1; $sendIndex <= (int) $challenge->send_count; $sendIndex++) {
                        $rows[] = $this->customerOtpRow($challenge, $sendIndex);
                    }
                }

                $inserted += DB::table('sms_deliveries')->insertOrIgnore($rows);
            });

        return $inserted;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerOtpRow(stdClass $challenge, int $sendIndex): array
    {
        $occurredAt = $sendIndex === 1
            ? ($challenge->created_at ?? $challenge->last_sent_at)
            : $challenge->last_sent_at;

        return [
            'account_id' => $challenge->account_id,
            'source_type' => CustomerOtpChallenge::class,
            'source_id' => $challenge->id,
            'purpose' => SmsDeliveryPurpose::CustomerOtp->value,
            'source_mode' => $this->sourceMode((string) $challenge->provider_scope),
            'provider' => $challenge->provider,
            'status' => SmsDeliveryStatus::Accepted->value,
            'recipient_phone' => $challenge->phone,
            'message_preview' => null,
            'idempotency_key' => 'legacy-customer-otp:'.$challenge->id.':send:'.$sendIndex,
            'estimated_segments' => 1,
            'billed_segments' => 1,
            'reserved_amount_cents' => 0,
            'currency' => 'UAH',
            'accepted_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $challenge->updated_at ?? $occurredAt,
        ];
    }

    private function sourceMode(string $legacyScope): string
    {
        return match ($legacyScope) {
            'platform', SmsSendingMode::LadnaService->value => SmsSendingMode::LadnaService->value,
            default => SmsSendingMode::OwnGateway->value,
        };
    }

    private function sanitizedError(?string $error): ?string
    {
        return $error === null ? null : Str::limit(strip_tags($error), 1000, '');
    }
}
