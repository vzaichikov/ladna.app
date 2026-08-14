<?php

namespace App\Support\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\FestivalWorkflowStepType;
use Illuminate\Support\Str;

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
                subject: $this->emailSubject($subject, $payload, $locale),
                greeting: __('app.festival_notification_greeting', ['name' => $recipientName], $locale),
                lines: $this->emailLines([$body], $payload, $locale),
                smsText: $isAuthored ? $subject."\n".$body : __('app.festival_notification_template_announcement_sms', locale: $locale),
            );
        }

        $replacements = [
            'festival' => (string) ($payload['festival'] ?? '—'),
            'entry_code' => (string) ($payload['entry_code'] ?? '—'),
            'entry' => (string) ($payload['entry_name'] ?? $payload['entry_code'] ?? '—'),
            'step' => (string) ($payload['step'] ?? '—'),
            'next_step' => (string) ($payload['next_step'] ?? '—'),
            'decision' => (string) ($payload['decision'] ?? $payload['status'] ?? '—'),
            'requirement' => (string) ($payload['requirement'] ?? '—'),
            'deadline' => (string) ($payload['deadline'] ?? $payload['correction_due_at'] ?? '—'),
            'charge' => (string) ($payload['charge'] ?? '—'),
            'rank' => (string) ($payload['rank'] ?? '—'),
            'order' => (string) ($payload['order_id'] ?? '—'),
            'count' => (string) ($payload['tickets_count'] ?? '—'),
        ];
        if ($type === FestivalNotificationType::EntryReviewed) {
            return $this->entryReviewed($locale, $recipientName, $payload, $replacements, $actionUrl);
        }

        if ($type === FestivalNotificationType::EntryStepReviewed) {
            return $this->entryStepReviewed($locale, $recipientName, $payload, $replacements, $actionUrl);
        }

        if ($type === FestivalNotificationType::RequirementReviewed) {
            return $this->requirementReviewed($locale, $recipientName, $payload, $replacements, $actionUrl);
        }

        $subject = $this->emailSubject(
            __('app.festival_notification_template_'.$type->value.'_subject', $replacements, $locale),
            $payload,
            $locale,
        );
        $body = __('app.festival_notification_template_'.$type->value.'_body', $replacements, $locale);

        return new FestivalNotificationMessage(
            subject: $subject,
            greeting: __('app.festival_notification_greeting', ['name' => $recipientName], $locale),
            lines: $this->emailLines([$body], $payload, $locale),
            smsText: __('app.festival_notification_template_'.$type->value.'_sms', $replacements, $locale),
            actionLabel: $this->actionLabel($type, $actionUrl, $locale),
            actionUrl: $actionUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $replacements
     */
    private function entryReviewed(string $locale, string $recipientName, array $payload, array $replacements, ?string $actionUrl): FestivalNotificationMessage
    {
        $status = FestivalEntryStatus::tryFrom((string) ($payload['status'] ?? $payload['decision'] ?? ''));
        $statusKey = match ($status) {
            FestivalEntryStatus::Accepted => 'accepted',
            FestivalEntryStatus::Rejected => 'rejected',
            default => 'reviewed',
        };
        $lines = [__('app.festival_notification_template_entry_reviewed_'.$statusKey.'_body', $replacements, $locale)];
        $this->appendReviewDetails($lines, $payload, $locale);

        return new FestivalNotificationMessage(
            subject: $this->emailSubject(
                __('app.festival_notification_template_entry_reviewed_subject', $replacements, $locale),
                $payload,
                $locale,
            ),
            greeting: __('app.festival_notification_greeting', ['name' => $recipientName], $locale),
            lines: $this->emailLines($lines, $payload, $locale),
            smsText: __('app.festival_notification_template_entry_reviewed_'.$statusKey.'_sms', $replacements, $locale),
            actionLabel: $this->actionLabel(FestivalNotificationType::EntryReviewed, $actionUrl, $locale),
            actionUrl: $actionUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $replacements
     */
    private function entryStepReviewed(string $locale, string $recipientName, array $payload, array $replacements, ?string $actionUrl): FestivalNotificationMessage
    {
        $decision = (string) ($payload['decision'] ?? '');
        $nextStepType = FestivalWorkflowStepType::tryFrom((string) ($payload['next_step_type'] ?? ''));
        $entryStatus = FestivalEntryStatus::tryFrom((string) ($payload['entry_status'] ?? ''));
        $template = match (true) {
            $decision === 'approve' && $nextStepType === FestivalWorkflowStepType::Payment => 'approved_payment',
            $decision === 'approve' && filled($payload['next_step'] ?? null) => 'approved_next',
            $decision === 'approve' && $entryStatus === FestivalEntryStatus::Accepted => 'approved_complete',
            $decision === 'approve' => 'approved',
            $decision === 'request_changes' => 'changes_requested',
            $decision === 'reject_entry' => 'rejected',
            default => 'reviewed',
        };
        $lines = [__('app.festival_notification_template_entry_step_reviewed_'.$template.'_body', $replacements, $locale)];
        $this->appendReviewDetails($lines, $payload, $locale);

        return new FestivalNotificationMessage(
            subject: $this->emailSubject(
                __('app.festival_notification_template_entry_step_reviewed_subject', $replacements, $locale),
                $payload,
                $locale,
            ),
            greeting: __('app.festival_notification_greeting', ['name' => $recipientName], $locale),
            lines: $this->emailLines($lines, $payload, $locale),
            smsText: __('app.festival_notification_template_entry_step_reviewed_'.$template.'_sms', $replacements, $locale),
            actionLabel: $this->actionLabel(FestivalNotificationType::EntryStepReviewed, $actionUrl, $locale),
            actionUrl: $actionUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $replacements
     */
    private function requirementReviewed(string $locale, string $recipientName, array $payload, array $replacements, ?string $actionUrl): FestivalNotificationMessage
    {
        $status = FestivalRequirementStatus::tryFrom((string) ($payload['status'] ?? $payload['decision'] ?? ''));
        $replacements['decision'] = $status
            ? __('app.festival_requirement_status_'.$status->value, locale: $locale)
            : $replacements['decision'];
        $lines = [__('app.festival_notification_template_requirement_reviewed_body', $replacements, $locale)];
        $this->appendReviewDetails($lines, $payload, $locale);

        return new FestivalNotificationMessage(
            subject: $this->emailSubject(
                __('app.festival_notification_template_requirement_reviewed_subject', $replacements, $locale),
                $payload,
                $locale,
            ),
            greeting: __('app.festival_notification_greeting', ['name' => $recipientName], $locale),
            lines: $this->emailLines($lines, $payload, $locale),
            smsText: __('app.festival_notification_template_requirement_reviewed_sms', $replacements, $locale),
            actionLabel: $this->actionLabel(FestivalNotificationType::RequirementReviewed, $actionUrl, $locale),
            actionUrl: $actionUrl,
        );
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $payload
     */
    private function appendReviewDetails(array &$lines, array $payload, string $locale): void
    {
        if (filled($payload['comment'] ?? null)) {
            $lines[] = __('app.festival_notification_review_comment', ['comment' => (string) $payload['comment']], $locale);
        }

        if (filled($payload['correction_due_at'] ?? null)) {
            $lines[] = __('app.festival_notification_correction_deadline', ['deadline' => (string) $payload['correction_due_at']], $locale);
        }
    }

    private function actionLabel(FestivalNotificationType $type, ?string $actionUrl, string $locale): ?string
    {
        if ($actionUrl === null) {
            return null;
        }

        return match ($type) {
            FestivalNotificationType::EntrySubmitted,
            FestivalNotificationType::EntryReviewed,
            FestivalNotificationType::EntryStepReviewed,
            FestivalNotificationType::RequirementReviewed,
            FestivalNotificationType::PaymentDue,
            FestivalNotificationType::PaymentPaid => __('app.festival_open_application', locale: $locale),
            FestivalNotificationType::SchedulePublished,
            FestivalNotificationType::ScheduleChanged => __('app.festival_view_schedule', locale: $locale),
            FestivalNotificationType::ResultsPublished => __('app.festival_view_results', locale: $locale),
            FestivalNotificationType::TicketsIssued => __('app.festival_open_tickets', locale: $locale),
            default => null,
        };
    }

    /** @param array<string, mixed> $payload */
    private function emailSubject(string $subject, array $payload, string $locale): string
    {
        $festival = trim((string) ($payload['festival'] ?? ''));

        if ($festival === '') {
            return $subject;
        }

        return Str::limit((string) __('app.festival_notification_subject_with_name', [
            'festival' => $festival,
            'subject' => $subject,
        ], $locale), 255);
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function emailLines(array $lines, array $payload, string $locale): array
    {
        $festival = trim((string) ($payload['festival'] ?? ''));

        if ($festival === '') {
            return $lines;
        }

        return [
            (string) __('app.festival_notification_festival_name', ['festival' => $festival], $locale),
            ...$lines,
        ];
    }
}
