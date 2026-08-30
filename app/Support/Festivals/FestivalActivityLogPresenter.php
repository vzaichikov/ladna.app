<?php

namespace App\Support\Festivals;

use App\Models\FestivalActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Throwable;

class FestivalActivityLogPresenter
{
    public function __construct(private readonly FestivalApplicationHistoryTypes $historyTypes) {}

    /** @return array{title: string, actor: string, details: array<int, string>, occurred_at: mixed, type: string, type_label: string} */
    public function present(FestivalActivityLog $activity, string $timezone, bool $canViewFinance): array
    {
        $titleKey = 'app.festival_activity_action_'.str_replace('.', '_', $activity->action);
        $type = $this->historyTypes->classify($activity->action);

        return [
            'title' => Lang::has($titleKey) ? __($titleKey) : __('app.festival_activity_action_updated'),
            'actor' => $activity->actorUser?->name
                ?? $activity->actorPortalUser?->displayName()
                ?? ($activity->actorAccountApiToken ? __('app.festival_activity_actor_api_token', ['name' => $activity->actorAccountApiToken->name]) : null)
                ?? __('app.festival_activity_actor_system'),
            'details' => $this->details($activity, $timezone, $canViewFinance),
            'occurred_at' => $activity->occurred_at,
            'type' => $type,
            'type_label' => $this->historyTypes->label($type),
        ];
    }

    /** @return array<int, string> */
    private function details(FestivalActivityLog $activity, string $timezone, bool $canViewFinance): array
    {
        $payload = is_array($activity->payload) ? $activity->payload : [];
        $details = [];

        if ($activity->action === 'entry.updated') {
            $fieldLabels = [
                'entry_name' => __('app.festival_entry_name'),
                'act_title' => __('app.festival_act_title'),
                'act_description' => __('app.festival_act_description'),
                'comments' => __('app.comment'),
                'participants' => __('app.festival_roster'),
            ];
            $fields = collect($payload['fields'] ?? [])
                ->filter(fn (mixed $field): bool => is_string($field) && isset($fieldLabels[$field]))
                ->map(fn (string $field): string => $fieldLabels[$field])
                ->values()
                ->all();

            if ($fields !== []) {
                $details[] = __('app.festival_activity_changed_fields', ['fields' => implode(', ', $fields)]);
            }
        }

        if (is_string($payload['step'] ?? null) && $payload['step'] !== '') {
            $details[] = __('app.festival_activity_step', ['step' => $payload['step']]);
        }

        $status = $payload['status'] ?? null;
        if (is_string($status) && $status !== ''
            && ($canViewFinance || ! str_starts_with($activity->action, 'payment.'))) {
            $details[] = __('app.festival_activity_status', [
                'status' => $this->translatedStatus($activity->action, $status),
            ]);
        }

        if (in_array($activity->action, ['entry.category_reassigned', 'schedule.rescheduled', 'score_sheet.unlocked'], true)
            && filled($payload['reason'] ?? null)) {
            $details[] = __('app.festival_activity_reason', ['reason' => (string) $payload['reason']]);
        }

        if (filled($payload['comment'] ?? null)) {
            $details[] = __('app.festival_activity_comment', ['comment' => (string) $payload['comment']]);
        } elseif (filled($payload['review_notes'] ?? null)) {
            $details[] = __('app.festival_activity_comment', ['comment' => (string) $payload['review_notes']]);
        }

        if ($activity->action === 'entry_step.request_changes' && filled($payload['correction_due_at'] ?? null)) {
            try {
                $deadline = CarbonImmutable::parse((string) $payload['correction_due_at'])
                    ->timezone($timezone)
                    ->format('d.m.Y H:i');
                $details[] = __('app.festival_activity_correction_due_at', ['date' => $deadline]);
            } catch (Throwable) {
                // An invalid legacy value is intentionally omitted from the human-readable audit view.
            }
        }

        if ($canViewFinance && in_array($activity->action, ['charge.manual_reviewed', 'payment.started', 'payment.status_changed'], true)) {
            if (filled($payload['provider'] ?? null)) {
                $details[] = __('app.festival_activity_payment_provider', ['provider' => (string) $payload['provider']]);
            }
            if (filled($payload['decision'] ?? null)) {
                $details[] = __('app.festival_activity_payment_decision', [
                    'decision' => __('app.festival_activity_payment_decision_'.$payload['decision']),
                ]);
            }
            if (filled($payload['from_status'] ?? null) && filled($payload['to_status'] ?? null)) {
                $details[] = __('app.festival_activity_payment_status_change', [
                    'from' => $this->translatedStatus($activity->action, (string) $payload['from_status']),
                    'to' => $this->translatedStatus($activity->action, (string) $payload['to_status']),
                ]);
            }
            if (filled($payload['notes'] ?? null)) {
                $details[] = __('app.festival_activity_comment', ['comment' => (string) $payload['notes']]);
            }
        }

        return $details;
    }

    private function translatedStatus(string $action, string $status): string
    {
        $key = match (true) {
            str_starts_with($action, 'payment.') => 'app.festival_payment_status_'.$status,
            $action === 'requirement.reviewed' => 'app.festival_requirement_status_'.$status,
            str_starts_with($action, 'entry_step.') => 'app.festival_step_status_'.$status,
            default => 'app.festival_entry_status_'.$status,
        };

        return Lang::has($key) ? __($key) : $status;
    }
}
