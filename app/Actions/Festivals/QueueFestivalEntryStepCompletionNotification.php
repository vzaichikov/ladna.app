<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStepStatus;
use App\Enums\FestivalNotificationType;
use App\Enums\FestivalTeamMemberType;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use App\Models\FestivalNotification;
use App\Models\FestivalParticipant;

class QueueFestivalEntryStepCompletionNotification
{
    public function __construct(private readonly FestivalNotificationOutbox $notifications) {}

    public function execute(FestivalEntryStep $approvedStep, string $dedupeSuffix, bool $queueOwnerTelegramAlert): ?FestivalNotification
    {
        $entry = FestivalEntry::query()
            ->with(['account', 'category', 'edition', 'participants', 'portalUser', 'steps.workflowStep'])
            ->whereKey($approvedStep->festival_entry_id)
            ->firstOrFail();
        $approvedStep = $entry->steps->firstWhere('id', $approvedStep->id) ?? abort(404);
        abort_unless($approvedStep->status === FestivalEntryStepStatus::Approved, 409);

        $currentStepIndex = $entry->steps->search(
            fn (FestivalEntryStep $entryStep): bool => $entryStep->is($approvedStep),
        );
        $nextStep = $currentStepIndex !== false
            ? $entry->steps->slice($currentStepIndex + 1)->first(
                fn (FestivalEntryStep $entryStep): bool => $entryStep->status !== FestivalEntryStepStatus::Approved,
            )
            : null;
        $locale = in_array($entry->portalUser->locale, ['uk', 'en'], true)
            ? $entry->portalUser->locale
            : (in_array($entry->account->default_language, ['uk', 'en'], true) ? $entry->account->default_language : 'uk');
        $participantNames = $entry->participants
            ->filter(fn (FestivalParticipant $participant): bool => $participant->member_type === FestivalTeamMemberType::Performer)
            ->map(fn (FestivalParticipant $participant): string => $participant->displayName())
            ->filter()
            ->join(', ');
        $replacements = [
            '%name%' => $participantNames !== '' ? $participantNames : $entry->entry_name,
            '%category%' => $entry->category->name,
        ];
        $configuredBodies = (array) data_get($approvedStep->workflowStep->config, 'completion_notifications.'.$locale, []);
        $channelBodies = collect(['email', 'sms', 'telegram'])
            ->mapWithKeys(function (string $channel) use ($configuredBodies, $replacements): array {
                $body = trim((string) ($configuredBodies[$channel] ?? ''));

                return $body === '' ? [] : [$channel => strtr($body, $replacements)];
            })
            ->all();

        return $this->notifications->queueForEntry(
            entry: $entry,
            type: FestivalNotificationType::EntryStepReviewed,
            payload: [
                'step' => $approvedStep->workflowStep->title,
                'decision' => 'approve',
                'comment' => $approvedStep->review_notes,
                'correction_due_at' => null,
                'entry_status' => $entry->status->value,
                'next_step' => $nextStep?->workflowStep->title,
                'next_step_type' => $nextStep?->workflowStep->type->value,
                'action_url' => route('festival.portal.entry-steps.show', [$entry->account->slug, $entry, $approvedStep]),
            ],
            dedupeSuffix: $dedupeSuffix,
            channelBodies: $channelBodies,
            queueOwnerTelegramAlert: $queueOwnerTelegramAlert,
        );
    }
}
