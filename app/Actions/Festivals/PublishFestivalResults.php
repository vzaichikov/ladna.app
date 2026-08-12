<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalNotificationType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalResult;
use App\Models\FestivalScoreSheet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishFestivalResults
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalNotificationOutbox $notifications,
        private readonly BuildFestivalResultPreview $preview,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(FestivalEdition $edition, FestivalCategory $category, User $actor, array $input = []): int
    {
        $edition->loadMissing('account');

        return DB::transaction(function () use ($edition, $category, $actor, $input): int {
            abort_unless($category->festival_edition_id === $edition->id && $category->account_id === $edition->account_id, 404);
            $entryIds = FestivalEntry::query()
                ->where('festival_edition_id', $edition->id)
                ->where('festival_category_id', $category->id)
                ->lockForUpdate()
                ->pluck('id');
            FestivalScoreSheet::query()->whereIn('festival_entry_id', $entryIds)->lockForUpdate()->get();

            $preview = $this->preview->execute($edition, $category);
            $tieBreaks = $this->validatedTieBreaks($preview['ties'], collect($input['tie_breaks'] ?? []));
            $ranked = $preview['rows']->sort(function (array $first, array $second) use ($tieBreaks): int {
                $scoreComparison = bccomp($second['total'], $first['total'], 4);

                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $orders = $tieBreaks[$first['total']]['orders'];

                return $orders[$first['entry']->id] <=> $orders[$second['entry']->id];
            })->values();
            $publishedAt = now();

            foreach ($ranked as $index => $row) {
                $rank = $index + 1;
                /** @var FestivalEntry $entry */
                $entry = $row['entry'];
                $tieBreak = $tieBreaks[$row['total']] ?? null;
                FestivalResult::query()->updateOrCreate(['festival_entry_id' => $entry->id], [
                    'account_id' => $edition->account_id,
                    'festival_edition_id' => $edition->id,
                    'total_score' => $row['total'],
                    'rank' => $rank,
                    'medal' => match ($rank) {
                        1 => 'gold', 2 => 'silver', 3 => 'bronze', default => null
                    },
                    'publication_details' => [
                        'award_total' => $row['award_total'],
                        'rubric_deductions' => $row['deduction_total'],
                        'ad_hoc_penalties' => $row['ad_hoc_penalties'],
                        'tie_break' => $tieBreak === null ? null : [
                            'reason' => $tieBreak['reason'],
                            'ordered_entry_ids' => array_keys(collect($tieBreak['orders'])->sort()->all()),
                        ],
                    ],
                    'published_at' => $publishedAt,
                ]);
                $this->notifications->queueForEntry($entry, FestivalNotificationType::ResultsPublished, [
                    'subject' => __('app.festival_results_notification_subject'),
                    'lines' => [__('app.festival_results_notification_copy', ['entry' => $entry->entry_name, 'rank' => $rank])],
                    'action_url' => route('festival.portal.entries.show', [$edition->account->slug, $entry]),
                    'action_label' => __('app.festival_view_results'),
                ], 'category:'.$category->id.':'.$publishedAt->toDateString());
            }

            $this->activity->record($category, 'results.published', $edition, $actor, [
                'entries' => $ranked->count(),
                'tie_breaks' => collect($tieBreaks)->map(fn (array $tieBreak, string $total): array => [
                    'total' => $total,
                    'reason' => $tieBreak['reason'],
                    'ordered_entry_ids' => array_keys(collect($tieBreak['orders'])->sort()->all()),
                ])->values()->all(),
            ]);

            return $ranked->count();
        }, 3);
    }

    /**
     * @param  Collection<int, array{total: string, rows: Collection<int, array<string, mixed>>}>  $ties
     * @param  Collection<int, array<string, mixed>>  $submittedTieBreaks
     * @return array<string, array{reason: string, orders: array<int, int>}>
     */
    private function validatedTieBreaks(Collection $ties, Collection $submittedTieBreaks): array
    {
        if ($ties->count() !== $submittedTieBreaks->count()) {
            throw ValidationException::withMessages(['tie_breaks' => __('app.festival_results_tie_break_required')]);
        }

        $validated = [];

        foreach ($ties as $tie) {
            $submitted = $submittedTieBreaks->first(fn (array $candidate): bool => bccomp((string) ($candidate['total'] ?? ''), $tie['total'], 4) === 0);
            $entryIds = $tie['rows']->pluck('entry.id')->map(fn (int $id): int => $id)->sort()->values();
            $orders = collect($submitted['orders'] ?? [])->mapWithKeys(fn (mixed $order, int|string $entryId): array => [(int) $entryId => (int) $order]);

            if (! is_array($submitted)
                || $orders->keys()->sort()->values()->all() !== $entryIds->all()
                || $orders->values()->sort()->values()->all() !== range(1, $entryIds->count())
                || trim((string) ($submitted['reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['tie_breaks' => __('app.festival_results_tie_break_invalid')]);
            }

            $validated[$tie['total']] = [
                'reason' => trim((string) $submitted['reason']),
                'orders' => $orders->all(),
            ];
        }

        return $validated;
    }
}
