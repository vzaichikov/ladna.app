<?php

namespace App\Support\Festivals;

use App\Enums\FestivalNotificationType;

final class FestivalNotificationRenderer
{
    /** @param array<string, mixed> $payload */
    public function render(FestivalNotificationType $type, string $locale, string $recipientName, array $payload): FestivalNotificationMessage
    {
        $actionUrl = filled($payload['action_url'] ?? null) ? (string) $payload['action_url'] : null;

        if ($type === FestivalNotificationType::Announcement) {
            $subject = (string) ($payload['subject'] ?? __('app.festival_notification_template_announcement_subject', locale: $locale));
            $body = (string) ($payload['body'] ?? data_get($payload, 'lines.0', __('app.festival_notification_template_announcement_body', locale: $locale)));
            $isAuthored = filled($payload['subject'] ?? null) || filled($payload['body'] ?? null) || filled(data_get($payload, 'lines.0'));

            return new FestivalNotificationMessage(
                subject: $subject,
                greeting: __('app.festival_notification_greeting', ['name' => $recipientName], $locale),
                lines: [$body],
                smsText: $isAuthored ? $subject."\n".$body : __('app.festival_notification_template_announcement_sms', locale: $locale),
            );
        }

        $replacements = [
            'festival' => (string) ($payload['festival'] ?? '—'),
            'entry_code' => (string) ($payload['entry_code'] ?? '—'),
            'entry' => (string) ($payload['entry_name'] ?? $payload['entry_code'] ?? '—'),
            'step' => (string) ($payload['step'] ?? '—'),
            'decision' => (string) ($payload['decision'] ?? $payload['status'] ?? '—'),
            'requirement' => (string) ($payload['requirement'] ?? '—'),
            'deadline' => (string) ($payload['deadline'] ?? $payload['correction_due_at'] ?? '—'),
            'charge' => (string) ($payload['charge'] ?? '—'),
            'rank' => (string) ($payload['rank'] ?? '—'),
            'order' => (string) ($payload['order_id'] ?? '—'),
            'count' => (string) ($payload['tickets_count'] ?? '—'),
        ];
        $subject = __('app.festival_notification_template_'.$type->value.'_subject', $replacements, $locale);
        $body = __('app.festival_notification_template_'.$type->value.'_body', $replacements, $locale);

        return new FestivalNotificationMessage(
            subject: $subject,
            greeting: __('app.festival_notification_greeting', ['name' => $recipientName], $locale),
            lines: [$body],
            smsText: __('app.festival_notification_template_'.$type->value.'_sms', $replacements, $locale),
            actionLabel: $this->actionLabel($type, $actionUrl, $locale),
            actionUrl: $actionUrl,
        );
    }

    private function actionLabel(FestivalNotificationType $type, ?string $actionUrl, string $locale): ?string
    {
        if ($actionUrl === null) {
            return null;
        }

        return match ($type) {
            FestivalNotificationType::SchedulePublished,
            FestivalNotificationType::ScheduleChanged => __('app.festival_view_schedule', locale: $locale),
            FestivalNotificationType::ResultsPublished => __('app.festival_view_results', locale: $locale),
            FestivalNotificationType::TicketsIssued => __('app.festival_open_tickets', locale: $locale),
            default => null,
        };
    }
}
