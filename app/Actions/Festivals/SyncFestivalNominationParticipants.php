<?php

namespace App\Actions\Festivals;

use App\Models\FestivalNomination;
use App\Models\FestivalPortalUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncFestivalNominationParticipants
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /**
     * @param  Collection<int, int>  $eligibleParticipantIds
     * @param  array<int, int|string>  $submittedParticipantIds
     */
    public function execute(FestivalNomination $nomination, Collection $eligibleParticipantIds, array $submittedParticipantIds, User|FestivalPortalUser $actor): FestivalNomination
    {
        return DB::transaction(function () use ($nomination, $eligibleParticipantIds, $submittedParticipantIds, $actor): FestivalNomination {
            $nomination = FestivalNomination::query()->with(['edition', 'participants'])->whereKey($nomination->id)->lockForUpdate()->firstOrFail();
            $eligibleIds = $eligibleParticipantIds->map(fn (mixed $id): int => (int) $id)->unique()->values();
            $submittedIds = collect($submittedParticipantIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();

            if ($submittedIds->diff($eligibleIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['participant_ids' => __('app.festival_nomination_participant_invalid')]);
            }

            $preservedIds = $nomination->participants->modelKeys();
            $targetIds = collect($preservedIds)->diff($eligibleIds)->merge($submittedIds)->unique()->values();
            $nomination->participants()->sync($targetIds->mapWithKeys(fn (int $id): array => [
                $id => ['account_id' => $nomination->account_id],
            ])->all());
            $this->activity->record($nomination, 'nomination.participants_updated', $nomination->edition, $actor, [
                'participant_ids' => $targetIds->all(),
            ]);

            return $nomination->refresh()->load('participants');
        }, 3);
    }
}
