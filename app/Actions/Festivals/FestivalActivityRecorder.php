<?php

namespace App\Actions\Festivals;

use App\Models\FestivalActivityLog;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FestivalActivityRecorder
{
    /** @param array<string, mixed> $payload */
    public function record(Model $subject, string $action, ?FestivalEdition $edition = null, User|FestivalPortalUser|null $actor = null, array $payload = []): FestivalActivityLog
    {
        $accountId = (int) ($subject->getAttribute('account_id') ?? $edition?->account_id);

        return FestivalActivityLog::query()->create([
            'account_id' => $accountId,
            'festival_edition_id' => $edition?->id ?? $subject->getAttribute('festival_edition_id'),
            'festival_entry_id' => $this->entryId($subject),
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'actor_portal_user_id' => $actor instanceof FestivalPortalUser ? $actor->id : null,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function entryId(Model $subject): ?int
    {
        if ($subject instanceof FestivalEntry) {
            return (int) $subject->getKey();
        }

        $entryId = $subject->getAttribute('festival_entry_id');

        if ($entryId !== null) {
            return (int) $entryId;
        }

        if (! $subject instanceof FestivalPaymentAttempt) {
            return null;
        }

        $charge = $subject->relationLoaded('charge')
            ? $subject->getRelation('charge')
            : $subject->charge()->first(['id', 'festival_entry_id']);

        return $charge?->festival_entry_id !== null ? (int) $charge->festival_entry_id : null;
    }
}
