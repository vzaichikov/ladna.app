<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalRubricSectionContribution;
use App\Enums\FestivalScoreSheetStatus;
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
use Illuminate\Validation\ValidationException;

class BuildFestivalResultPreview
{
    public function __construct(private readonly FestivalJudgingCriteria $judgingCriteria) {}

    /**
     * @return array{
     *     rubric: FestivalRubric,
     *     rows: Collection<int, array{entry: FestivalEntry, total: string, award_total: string, deduction_total: string, ad_hoc_penalties: string}>,
     *     ties: Collection<int, array{total: string, rows: Collection<int, array{entry: FestivalEntry, total: string, award_total: string, deduction_total: string, ad_hoc_penalties: string}>}>
     * }
     */
    public function execute(FestivalEdition $edition, FestivalCategory $category): array
    {
        abort_unless($category->festival_edition_id === $edition->id && $category->account_id === $edition->account_id, 404);
        $rubric = $this->judgingCriteria->rubricForCategory($edition, $category);

        if (! $rubric) {
            throw ValidationException::withMessages(['category' => __('app.festival_results_rubric_missing')]);
        }

        $rubric->load('sections.criteria');
        $assignments = $this->judgingCriteria->activeAssignments($category);
        $uncoveredSections = $this->judgingCriteria->uncoveredSections($rubric, $assignments);

        if ($uncoveredSections->isNotEmpty()) {
            throw ValidationException::withMessages([
                'category' => __('app.festival_results_criteria_uncovered', ['sections' => $uncoveredSections->pluck('name')->join(', ')]),
            ]);
        }

        $eligibleAssignments = $assignments
            ->filter(fn (FestivalJudgeAssignment $assignment): bool => $this->judgingCriteria->sectionsFor($assignment, $rubric)->isNotEmpty())
            ->values();
        $assignmentIds = $eligibleAssignments->modelKeys();
        $entries = FestivalEntry::query()
            ->with([
                'penalties',
                'portalUser',
                'scoreSheets' => fn ($query) => $query
                    ->where('festival_rubric_id', $rubric->id)
                    ->whereIn('festival_judge_assignment_id', $assignmentIds)
                    ->with(['assignment.rubricSections', 'scores']),
            ])
            ->where('festival_edition_id', $edition->id)
            ->where('festival_category_id', $category->id)
            ->where('status', FestivalEntryStatus::Accepted->value)
            ->orderBy('id')
            ->get();

        if ($entries->count() < $category->minimum_entries_to_run) {
            throw ValidationException::withMessages([
                'category' => __('app.festival_category_minimum_entries_required', ['minimum' => $category->minimum_entries_to_run]),
            ]);
        }

        $rows = $entries->map(fn (FestivalEntry $entry): array => $this->entryRow($entry, $rubric, $eligibleAssignments));
        $rows = $rows->sort(function (array $first, array $second): int {
            $scoreComparison = bccomp($second['total'], $first['total'], 4);

            return $scoreComparison !== 0 ? $scoreComparison : $first['entry']->id <=> $second['entry']->id;
        })->values();
        $ties = $rows
            ->groupBy('total')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group, string $total): array => ['total' => $total, 'rows' => $group->values()])
            ->values();

        return compact('rubric', 'rows', 'ties');
    }

    /**
     * @param  Collection<int, FestivalJudgeAssignment>  $assignments
     * @return array{entry: FestivalEntry, total: string, award_total: string, deduction_total: string, ad_hoc_penalties: string}
     */
    private function entryRow(FestivalEntry $entry, FestivalRubric $rubric, Collection $assignments): array
    {
        $sheets = $entry->scoreSheets->keyBy('festival_judge_assignment_id');

        if ($assignments->contains(function (FestivalJudgeAssignment $assignment) use ($sheets): bool {
            $sheet = $sheets->get($assignment->id);

            return ! $sheet instanceof FestivalScoreSheet || $sheet->status !== FestivalScoreSheetStatus::Submitted;
        })) {
            throw ValidationException::withMessages(['category' => __('app.festival_results_scores_incomplete')]);
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

        return [
            'entry' => $entry,
            'total' => $this->round($total),
            'award_total' => $this->round($awardTotal),
            'deduction_total' => $this->round($deductionTotal),
            'ad_hoc_penalties' => $this->round($penalties),
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
        $scoreTotal = '0.00000000';

        foreach ($coveringAssignments as $assignment) {
            $score = $sheets->get($assignment->id)?->scores->firstWhere('festival_rubric_criterion_id', $criterion->id);

            if (! $score instanceof FestivalCriterionScore) {
                throw ValidationException::withMessages(['category' => __('app.festival_results_scores_incomplete')]);
            }

            $scoreTotal = bcadd($scoreTotal, (string) $score->score, 8);
        }

        $average = bcdiv($scoreTotal, (string) $coveringAssignments->count(), 8);

        return bcmul(bcmul($average, (string) $criterion->weight, 8), (string) $section->weight, 8);
    }

    private function round(string $value): string
    {
        $increment = bccomp($value, '0', 8) >= 0 ? '0.00005' : '-0.00005';

        return bcadd($value, $increment, 4);
    }
}
