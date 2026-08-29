<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalRubricSectionContribution;
use App\Models\FestivalCategory;
use App\Models\FestivalCriterionScore;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalRubricSection;
use App\Models\FestivalScoreSheet;
use App\Support\Festivals\FestivalJudgingCriteria;
use Illuminate\Support\Collection;

class BuildFestivalResults
{
    public function __construct(private readonly FestivalJudgingCriteria $judgingCriteria) {}

    /**
     * @return array{
     *     rubric: FestivalRubric|null,
     *     rows: Collection<int, array{rank: int, tied: bool, entry: FestivalEntry, total: string, award_total: string, deduction_total: string, ad_hoc_penalties: string, required: int, completed: int, missing: int, ready: bool}>,
     *     issues: Collection<int, string>,
     *     required: int,
     *     completed: int,
     *     missing: int,
     *     ready: bool
     * }
     */
    public function execute(FestivalEdition $edition, FestivalCategory $category): array
    {
        abort_unless($category->festival_edition_id === $edition->id && $category->account_id === $edition->account_id, 404);
        $rubric = $this->judgingCriteria->rubricForCategory($edition, $category);
        $issues = collect();

        if (! $rubric) {
            return [
                'rubric' => null,
                'rows' => collect(),
                'issues' => collect([__('app.festival_results_rubric_missing')]),
                'required' => 0,
                'completed' => 0,
                'missing' => 0,
                'ready' => false,
            ];
        }

        $rubric->load('sections.criteria');
        $assignments = $this->judgingCriteria->activeAssignments($category);
        $uncoveredSections = $this->judgingCriteria->uncoveredSections($rubric, $assignments);

        if ($uncoveredSections->isNotEmpty()) {
            $issues->push(__('app.festival_results_criteria_uncovered', ['sections' => $uncoveredSections->pluck('name')->join(', ')]));
        }

        $eligibleAssignments = $assignments
            ->filter(fn (FestivalJudgeAssignment $assignment): bool => $this->judgingCriteria->sectionsFor($assignment, $rubric)->isNotEmpty())
            ->values();
        $assignmentIds = $eligibleAssignments->modelKeys();
        $entries = FestivalEntry::query()
            ->with([
                'penalties',
                'participants.nominations' => fn ($query) => $query->where('festival_edition_id', $edition->id),
                'scoreSheets' => fn ($query) => $query
                    ->where('festival_rubric_id', $rubric->id)
                    ->whereIn('festival_judge_assignment_id', $assignmentIds)
                    ->with(['scores', 'assignment']),
            ])
            ->where('festival_edition_id', $edition->id)
            ->where('festival_category_id', $category->id)
            ->where('status', FestivalEntryStatus::Accepted->value)
            ->orderBy('id')
            ->get();

        if ($entries->count() < $category->minimum_entries_to_run) {
            $issues->push(__('app.festival_category_minimum_entries_required', ['minimum' => $category->minimum_entries_to_run]));
        }

        $rows = $entries
            ->map(fn (FestivalEntry $entry): array => $this->entryRow($entry, $rubric, $eligibleAssignments))
            ->sort(function (array $first, array $second): int {
                $scoreComparison = bccomp($second['total'], $first['total'], 4);

                return $scoreComparison !== 0 ? $scoreComparison : $first['entry']->id <=> $second['entry']->id;
            })
            ->values();
        $scoreCounts = $rows->countBy('total');
        $previousTotal = null;
        $rank = 0;
        $rows = $rows->map(function (array $row, int $index) use (&$previousTotal, &$rank, $scoreCounts): array {
            if ($previousTotal === null || bccomp($row['total'], $previousTotal, 4) !== 0) {
                $rank = $index + 1;
                $previousTotal = $row['total'];
            }

            return [
                'rank' => $rank,
                'tied' => $scoreCounts->get($row['total'], 0) > 1,
                ...$row,
            ];
        });
        $required = $rows->sum('required');
        $completed = $rows->sum('completed');
        $missing = max(0, $required - $completed);

        return [
            'rubric' => $rubric,
            'rows' => $rows,
            'issues' => $issues,
            'required' => $required,
            'completed' => $completed,
            'missing' => $missing,
            'ready' => $issues->isEmpty() && $rows->isNotEmpty() && $required > 0 && $missing === 0,
        ];
    }

    /**
     * @param  Collection<int, FestivalJudgeAssignment>  $assignments
     * @return array{entry: FestivalEntry, total: string, award_total: string, deduction_total: string, ad_hoc_penalties: string, required: int, completed: int, missing: int, ready: bool}
     */
    private function entryRow(FestivalEntry $entry, FestivalRubric $rubric, Collection $assignments): array
    {
        $sheets = $entry->scoreSheets->keyBy('festival_judge_assignment_id');
        $required = 0;
        $completed = 0;

        foreach ($assignments as $assignment) {
            $sheet = $sheets->get($assignment->id);

            foreach ($this->judgingCriteria->sectionsFor($assignment, $rubric) as $section) {
                foreach ($section->criteria as $criterion) {
                    $required++;

                    $criterionScore = $sheet?->scores->firstWhere('festival_rubric_criterion_id', $criterion->id);

                    if ($criterionScore instanceof FestivalCriterionScore && $criterionScore->score !== null) {
                        $completed++;
                    }
                }
            }
        }

        $awardTotal = '0.00000000';
        $deductionTotal = '0.00000000';

        foreach ($rubric->sections as $section) {
            foreach ($section->criteria as $criterion) {
                $criterionTotal = $this->criterionTotal($criterion, $section, $rubric, $assignments, $sheets);

                if ($section->contribution === FestivalRubricSectionContribution::Deduction) {
                    $deductionTotal = bcadd($deductionTotal, $criterionTotal, 8);
                } else {
                    $awardTotal = bcadd($awardTotal, $criterionTotal, 8);
                }
            }
        }

        $penalties = $entry->penalties->reduce(
            fn (string $total, $penalty): string => bcadd($total, (string) $penalty->points, 8),
            '0.00000000',
        );
        $total = bcsub(bcsub($awardTotal, $deductionTotal, 8), $penalties, 8);
        $missing = max(0, $required - $completed);

        return [
            'entry' => $entry,
            'total' => $this->round($total),
            'award_total' => $this->round($awardTotal),
            'deduction_total' => $this->round($deductionTotal),
            'ad_hoc_penalties' => $this->round($penalties),
            'required' => $required,
            'completed' => $completed,
            'missing' => $missing,
            'ready' => $required > 0 && $missing === 0,
        ];
    }

    /**
     * @param  Collection<int, FestivalJudgeAssignment>  $assignments
     * @param  Collection<int, FestivalScoreSheet>  $sheets
     */
    private function criterionTotal(FestivalRubricCriterion $criterion, FestivalRubricSection $section, FestivalRubric $rubric, Collection $assignments, Collection $sheets): string
    {
        $coveringAssignments = $assignments->filter(
            fn (FestivalJudgeAssignment $assignment): bool => $this->judgingCriteria->sectionsFor($assignment, $rubric)->contains('id', $section->id),
        );
        $scores = $coveringAssignments
            ->map(fn (FestivalJudgeAssignment $assignment): ?FestivalCriterionScore => $sheets
                ->get($assignment->id)?->scores->firstWhere('festival_rubric_criterion_id', $criterion->id))
            ->filter(fn (?FestivalCriterionScore $score): bool => $score instanceof FestivalCriterionScore && $score->score !== null)
            ->values();

        if ($scores->isEmpty()) {
            return '0.00000000';
        }

        $scoreTotal = $scores->reduce(
            fn (string $total, FestivalCriterionScore $score): string => bcadd($total, (string) $score->score, 8),
            '0.00000000',
        );
        $average = bcdiv($scoreTotal, (string) $scores->count(), 8);

        return bcmul(bcmul($average, (string) $criterion->weight, 8), (string) $section->weight, 8);
    }

    private function round(string $value): string
    {
        $increment = bccomp($value, '0', 8) >= 0 ? '0.00005' : '-0.00005';

        return bcadd($value, $increment, 4);
    }
}
