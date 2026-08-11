<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalNotificationType;
use App\Enums\FestivalScoreSheetStatus;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishFestivalResults
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    public function execute(FestivalEdition $edition, FestivalCategory $category, User $actor): int
    {
        $edition->loadMissing('account');

        return DB::transaction(function () use ($edition, $category, $actor): int {
            abort_unless($category->festival_edition_id === $edition->id && $category->account_id === $edition->account_id, 404);
            $entries = FestivalEntry::query()
                ->with(['scoreSheets', 'penalties', 'portalUser'])
                ->where('festival_edition_id', $edition->id)
                ->where('festival_category_id', $category->id)
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty() || $entries->contains(fn (FestivalEntry $entry): bool => $entry->scoreSheets->isEmpty() || $entry->scoreSheets->contains(fn ($sheet): bool => $sheet->status !== FestivalScoreSheetStatus::Submitted))) {
                throw ValidationException::withMessages(['category' => __('app.festival_results_scores_incomplete')]);
            }

            $ranked = $entries->map(function (FestivalEntry $entry): array {
                $judgeTotal = (float) $entry->scoreSheets->avg('total_score');
                $penalties = (float) $entry->penalties->sum('points');

                return ['entry' => $entry, 'total' => round($judgeTotal - $penalties, 4), 'judge_total' => $judgeTotal, 'penalties' => $penalties];
            })->sortBy([['total', 'desc'], ['entry.id', 'asc']])->values();

            $lastScore = null;
            $rank = 0;
            foreach ($ranked as $index => $row) {
                if ($lastScore === null || $row['total'] !== $lastScore) {
                    $rank = $index + 1;
                    $lastScore = $row['total'];
                }
                /** @var FestivalEntry $entry */
                $entry = $row['entry'];
                FestivalResult::query()->updateOrCreate(['festival_entry_id' => $entry->id], [
                    'account_id' => $edition->account_id,
                    'festival_edition_id' => $edition->id,
                    'total_score' => $row['total'],
                    'rank' => $rank,
                    'medal' => match ($rank) {
                        1 => 'gold', 2 => 'silver', 3 => 'bronze', default => null
                    },
                    'published_at' => now(),
                ]);
                $this->notifications->queueForEntry($entry, FestivalNotificationType::ResultsPublished, [
                    'subject' => __('app.festival_results_notification_subject'),
                    'lines' => [__('app.festival_results_notification_copy', ['entry' => $entry->entry_name, 'rank' => $rank])],
                    'action_url' => route('festival.portal.entries.show', [$edition->account->slug, $entry]),
                    'action_label' => __('app.festival_view_results'),
                ], 'category:'.$category->id.':'.now()->toDateString());
            }

            $this->activity->record($category, 'results.published', $edition, $actor, ['entries' => $entries->count()]);

            return $entries->count();
        }, 3);
    }
}
