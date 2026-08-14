<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalRequirementStatus;
use App\Enums\TelegramAlertType;
use App\Models\Account;
use Illuminate\Support\Facades\Lang;

class FestivalOwnerTelegramAlertRenderer implements TelegramAlertRenderer
{
    public function type(): TelegramAlertType
    {
        return TelegramAlertType::FestivalUpdate;
    }

    /** @param array<string, mixed> $payload */
    public function render(Account $account, array $payload): string
    {
        $locale = $this->locale($account);
        $notificationType = FestivalNotificationType::tryFrom((string) ($payload['notification_type'] ?? ''));
        $lines = [
            Lang::get('app.festival_owner_telegram_title', [
                'type' => $notificationType
                    ? Lang::get('app.festival_notification_type_'.$notificationType->value, [], $locale)
                    : Lang::get('app.festival_notification_update', [], $locale),
            ], $locale),
            Lang::get('app.festival_owner_telegram_festival', ['festival' => (string) ($payload['festival'] ?? '—')], $locale),
        ];

        $this->append($lines, 'app.festival_owner_telegram_application', 'application', $payload['entry'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_applicant', 'applicant', $payload['applicant'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_category', 'category', $payload['category'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_step', 'step', $payload['step'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_requirement', 'requirement', $payload['requirement'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_payment', 'payment', $payload['charge'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_deadline', 'deadline', $payload['deadline'] ?? $payload['correction_due_at'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_result', 'result', filled($payload['rank'] ?? null) ? '#'.$payload['rank'] : null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_tickets', 'count', $payload['tickets_count'] ?? null, $locale);

        $decision = $this->decisionLabel((string) ($payload['decision'] ?? $payload['status'] ?? ''), $locale);
        $this->append($lines, 'app.festival_owner_telegram_decision', 'decision', $decision, $locale);
        $this->append($lines, 'app.festival_owner_telegram_comment', 'comment', $payload['comment'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_message', 'message', $payload['body'] ?? null, $locale);
        $this->append($lines, 'app.festival_owner_telegram_next_step', 'next_step', $payload['next_step'] ?? null, $locale);

        if (filled($payload['staff_url'] ?? null)) {
            $lines[] = (string) $payload['staff_url'];
        }

        return implode("\n", $lines);
    }

    /** @param array<int, string> $lines */
    private function append(array &$lines, string $translationKey, string $replacement, mixed $value, string $locale): void
    {
        if (filled($value)) {
            $lines[] = Lang::get($translationKey, [$replacement => (string) $value], $locale);
        }
    }

    private function decisionLabel(string $decision, string $locale): string
    {
        if ($entryStatus = FestivalEntryStatus::tryFrom($decision)) {
            return Lang::get('app.festival_entry_status_'.$entryStatus->value, [], $locale);
        }

        if ($requirementStatus = FestivalRequirementStatus::tryFrom($decision)) {
            return Lang::get('app.festival_requirement_status_'.$requirementStatus->value, [], $locale);
        }

        return match ($decision) {
            'approve' => Lang::get('app.festival_notification_decision_approve', [], $locale),
            'request_changes' => Lang::get('app.festival_notification_decision_request_changes', [], $locale),
            'reject_entry' => Lang::get('app.festival_notification_decision_reject_entry', [], $locale),
            default => $decision,
        };
    }

    private function locale(Account $account): string
    {
        $locale = (string) $account->default_language;

        return array_key_exists($locale, config('ladna.locales', [])) ? $locale : config('app.locale');
    }
}
