<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalBattleMatchStatus;
use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalCompetitionFormat;
use App\Enums\FestivalEntryStatus;
use App\Models\FestivalBattleJudgeVote;
use App\Models\FestivalBattleMatch;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateFestivalBattleBracket
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /** @return Collection<int, FestivalBattleMatch> */
    public function execute(FestivalEdition $edition, FestivalCategory $category, User $actor, bool $regenerate = false): Collection
    {
        return DB::transaction(function () use ($edition, $category, $actor, $regenerate): Collection {
            FestivalEdition::query()
                ->whereKey($edition->id)
                ->where('account_id', $edition->account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $category = FestivalCategory::query()
                ->whereKey($category->id)
                ->where('account_id', $edition->account_id)
                ->where('festival_edition_id', $edition->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($category->competition_format !== FestivalCompetitionFormat::Knockout) {
                throw ValidationException::withMessages(['category' => __('app.festival_battle_category_not_knockout')]);
            }

            $existingMatches = FestivalBattleMatch::query()
                ->where('festival_category_id', $category->id)
                ->lockForUpdate()
                ->get();

            if ($existingMatches->isNotEmpty()) {
                if (! $regenerate) {
                    throw ValidationException::withMessages(['category' => __('app.festival_battle_bracket_exists')]);
                }

                $hasVotes = FestivalBattleJudgeVote::query()
                    ->whereIn('festival_battle_match_id', $existingMatches->modelKeys())
                    ->exists();
                $hasCompletedContest = $existingMatches->contains(
                    fn (FestivalBattleMatch $match): bool => $match->status === FestivalBattleMatchStatus::Completed
                        && $match->entry_a_id !== null
                        && $match->entry_b_id !== null,
                );

                if ($hasVotes || $hasCompletedContest) {
                    throw ValidationException::withMessages(['category' => __('app.festival_battle_regeneration_locked')]);
                }

                FestivalBattleMatch::query()->whereKey($existingMatches->modelKeys())->update([
                    'next_match_id' => null,
                    'next_position' => null,
                ]);
                FestivalBattleMatch::query()->whereKey($existingMatches->modelKeys())->delete();
            }

            $entryIds = FestivalEntry::query()
                ->where('festival_edition_id', $edition->id)
                ->where('festival_category_id', $category->id)
                ->where('status', FestivalEntryStatus::Accepted->value)
                ->whereHas('charges', fn ($query) => $query
                    ->where('kind', 'participation')
                    ->where('status', FestivalChargeStatus::Paid->value))
                ->whereDoesntHave('charges', fn ($query) => $query->whereNotIn('status', [FestivalChargeStatus::Paid->value, FestivalChargeStatus::Cancelled->value]))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->shuffle()
                ->values();
            $minimumEntries = max(2, (int) $category->minimum_entries_to_run);

            if ($entryIds->count() < $minimumEntries) {
                throw ValidationException::withMessages([
                    'category' => __('app.festival_battle_minimum_entries', ['minimum' => $minimumEntries]),
                ]);
            }

            $bracketSize = $this->nextPowerOfTwo($entryIds->count());
            $matchesByRound = $this->createMatches($category, $bracketSize);
            $this->linkMatches($matchesByRound);
            $this->seedFirstRound($matchesByRound->firstOrFail(), $entryIds);

            $this->activity->record(
                $category,
                $existingMatches->isEmpty() ? 'battle.bracket_generated' : 'battle.bracket_regenerated',
                $edition,
                $actor,
                ['entry_ids' => $entryIds->all(), 'bracket_size' => $bracketSize],
            );

            return FestivalBattleMatch::query()
                ->where('festival_category_id', $category->id)
                ->with(['entryA', 'entryB', 'winner'])
                ->orderBy('round')
                ->orderBy('position')
                ->get();
        }, 3);
    }

    private function nextPowerOfTwo(int $entryCount): int
    {
        $size = 2;

        while ($size < $entryCount) {
            $size *= 2;
        }

        return $size;
    }

    /** @return Collection<int, Collection<int, FestivalBattleMatch>> */
    private function createMatches(FestivalCategory $category, int $bracketSize): Collection
    {
        $matchesByRound = collect();
        $round = 1;

        for ($matchCount = intdiv($bracketSize, 2); $matchCount >= 1; $matchCount = intdiv($matchCount, 2)) {
            $roundMatches = collect();

            for ($position = 1; $position <= $matchCount; $position++) {
                $roundMatches->push(FestivalBattleMatch::query()->create([
                    'account_id' => $category->account_id,
                    'festival_edition_id' => $category->festival_edition_id,
                    'festival_category_id' => $category->id,
                    'round' => $round,
                    'position' => $position,
                    'status' => FestivalBattleMatchStatus::Pending,
                ]));
            }

            $matchesByRound->put($round, $roundMatches);
            $round++;
        }

        return $matchesByRound;
    }

    /** @param Collection<int, Collection<int, FestivalBattleMatch>> $matchesByRound */
    private function linkMatches(Collection $matchesByRound): void
    {
        $lastRound = $matchesByRound->keys()->max();

        foreach ($matchesByRound as $round => $matches) {
            if ($round === $lastRound) {
                continue;
            }

            $nextRoundMatches = $matchesByRound->get($round + 1);

            foreach ($matches->values() as $index => $match) {
                $match->update([
                    'next_match_id' => $nextRoundMatches->get(intdiv($index, 2))->id,
                    'next_position' => $index % 2 === 0 ? 'a' : 'b',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, FestivalBattleMatch>  $matches
     * @param  Collection<int, int>  $entryIds
     */
    private function seedFirstRound(Collection $matches, Collection $entryIds): void
    {
        $matchCount = $matches->count();

        foreach ($matches->values() as $index => $match) {
            $entryAId = $entryIds->get($index);
            $entryBId = $entryIds->get($matchCount + $index);

            if ($entryBId !== null) {
                $match->update([
                    'entry_a_id' => $entryAId,
                    'entry_b_id' => $entryBId,
                    'status' => FestivalBattleMatchStatus::Ready,
                ]);

                continue;
            }

            $match->update([
                'entry_a_id' => $entryAId,
                'status' => FestivalBattleMatchStatus::Completed,
                'winner_entry_id' => $entryAId,
                'combined_percentage_a' => 100,
                'combined_percentage_b' => 0,
                'finalized_at' => now(),
            ]);
            $this->advanceWinner($match, $entryAId);
        }
    }

    private function advanceWinner(FestivalBattleMatch $match, int $winnerEntryId): void
    {
        if ($match->next_match_id === null || $match->next_position === null) {
            return;
        }

        $nextMatch = FestivalBattleMatch::query()
            ->whereKey($match->next_match_id)
            ->where('account_id', $match->account_id)
            ->where('festival_edition_id', $match->festival_edition_id)
            ->where('festival_category_id', $match->festival_category_id)
            ->lockForUpdate()
            ->firstOrFail();
        $column = $match->next_position === 'a' ? 'entry_a_id' : 'entry_b_id';
        $existingEntryId = $nextMatch->getAttribute($column);

        if ($existingEntryId !== null && (int) $existingEntryId !== $winnerEntryId) {
            throw ValidationException::withMessages(['category' => __('app.festival_battle_advancement_conflict')]);
        }

        $nextMatch->setAttribute($column, $winnerEntryId);

        if ($nextMatch->entry_a_id !== null && $nextMatch->entry_b_id !== null) {
            $nextMatch->status = FestivalBattleMatchStatus::Ready;
        }

        $nextMatch->save();
    }
}
