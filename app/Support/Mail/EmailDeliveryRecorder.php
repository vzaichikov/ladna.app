<?php

namespace App\Support\Mail;

use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailScenario;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\Customer;
use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;

class EmailDeliveryRecorder
{
    public function createPending(
        Account $account,
        ?Customer $customer,
        ?User $user,
        EmailScenario $scenario,
        string $email,
        ?string $name,
        string $locale,
        TransactionalMail $mail,
        MailDeliverySettings $settings,
    ): EmailDelivery {
        return EmailDelivery::query()->create([
            'account_id' => $account->getKey(),
            'customer_id' => $customer?->getKey(),
            'user_id' => $user?->getKey(),
            'scenario' => $scenario,
            'status' => EmailDeliveryStatus::Pending,
            'recipient_kind' => $scenario->recipientKind(),
            'recipient_name' => $name,
            'recipient_email' => $email,
            'locale' => $locale,
            'account_timezone' => $account->timezone ?: config('app.timezone'),
            'subject_key' => $mail->subjectKey,
            'subject_parameters' => $mail->subjectParameters,
            'content_view' => $mail->contentView,
            'payload' => $mail->data,
            'configured_engine' => $settings->engine->value,
            'queued_at' => now(),
        ]);
    }

    public function startProcessing(int $deliveryId, Email $message): ?EmailDelivery
    {
        return DB::transaction(function () use ($deliveryId, $message): ?EmailDelivery {
            $delivery = EmailDelivery::query()->lockForUpdate()->find($deliveryId);

            if (! $delivery || in_array($delivery->status, [EmailDeliveryStatus::Sent, EmailDeliveryStatus::Skipped], true)) {
                return $delivery;
            }

            $delivery->forceFill([
                'status' => EmailDeliveryStatus::Processing,
                'subject' => $message->getSubject(),
                'html_body' => $message->getHtmlBody(),
                'text_body' => $message->getTextBody(),
                'attempts' => $delivery->attempts + 1,
                'processing_at' => now(),
                'failed_at' => null,
                'status_reason' => null,
                'last_error' => null,
            ])->save();

            return $delivery->refresh();
        });
    }

    public function markSkipped(int $deliveryId, Email $message, string $reason): void
    {
        DB::transaction(function () use ($deliveryId, $message, $reason): void {
            $delivery = EmailDelivery::query()->lockForUpdate()->find($deliveryId);

            if (! $delivery || in_array($delivery->status, [EmailDeliveryStatus::Sent, EmailDeliveryStatus::Skipped], true)) {
                return;
            }

            $delivery->forceFill([
                'status' => EmailDeliveryStatus::Skipped,
                'subject' => $message->getSubject(),
                'html_body' => $message->getHtmlBody(),
                'text_body' => $message->getTextBody(),
                'attempts' => $delivery->attempts + 1,
                'skipped_at' => now(),
                'status_reason' => $reason,
            ])->save();
        });
    }

    public function recordTransportSuccess(
        int $deliveryId,
        string $actualEngine,
        bool $fallbackUsed,
        ?string $providerMessageId,
    ): void {
        EmailDelivery::query()
            ->whereKey($deliveryId)
            ->whereNotIn('status', [EmailDeliveryStatus::Sent->value, EmailDeliveryStatus::Skipped->value])
            ->update([
                'actual_engine' => $actualEngine,
                'fallback_used' => $fallbackUsed,
                'provider_message_id' => $providerMessageId,
            ]);
    }

    public function recordTransportFailure(int $deliveryId, Throwable $exception): void
    {
        EmailDelivery::query()
            ->whereKey($deliveryId)
            ->whereNotIn('status', [EmailDeliveryStatus::Sent->value, EmailDeliveryStatus::Skipped->value])
            ->update(['last_error' => $this->sanitizedError($exception)]);
    }

    public function markSent(int $deliveryId, ?string $providerMessageId): void
    {
        EmailDelivery::query()
            ->whereKey($deliveryId)
            ->whereNotIn('status', [EmailDeliveryStatus::Sent->value, EmailDeliveryStatus::Skipped->value])
            ->update([
                'status' => EmailDeliveryStatus::Sent,
                'provider_message_id' => $providerMessageId,
                'sent_at' => now(),
                'failed_at' => null,
                'status_reason' => null,
            ]);
    }

    public function markFailed(int $deliveryId, Throwable $exception): void
    {
        EmailDelivery::query()
            ->whereKey($deliveryId)
            ->whereNotIn('status', [EmailDeliveryStatus::Sent->value, EmailDeliveryStatus::Skipped->value])
            ->update([
                'status' => EmailDeliveryStatus::Failed,
                'failed_at' => now(),
                'status_reason' => 'transport_failed',
                'last_error' => $this->sanitizedError($exception),
            ]);
    }

    private function sanitizedError(Throwable $exception): string
    {
        $message = strip_tags($exception->getMessage());
        $message = preg_replace(
            '/\b(password|api[_-]?key|token|secret)\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $message,
        ) ?? $message;

        return Str::limit($message !== '' ? $message : $exception::class, 2000);
    }
}
