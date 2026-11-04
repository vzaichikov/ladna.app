<?php

namespace App\Support\Festivals;

use App\Enums\FestivalRubricSectionContribution;
use App\Models\FestivalCategory;
use App\Models\FestivalCriterionScore;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalRubricSection;
use App\Models\FestivalScoreSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FestivalJudgingCriteria
{
    public function rubricForCategory(FestivalEdition $edition, FestivalCategory $category): ?FestivalRubric
    {
        return FestivalRubric::query()
            ->where('festival_edition_id', $edition->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('festival_category_id', $category->id)->orWhereNull('festival_category_id'))
            ->orderByRaw('festival_category_id is null')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /** @return Collection<int, FestivalJudgeAssignment> */
    public function activeAssignments(FestivalCategory $category): Collection
    {
        return FestivalJudgeAssignment::query()
            ->where('festival_edition_id', $category->festival_edition_id)
            ->where('is_active', true)
            ->whereHas('categories', fn ($query) => $query->whereKey($category->id))
            ->with('rubricSections')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, FestivalRubricSection> */
    public function sectionsFor(FestivalJudgeAssignment $assignment, FestivalRubric $rubric): Collection
    {
        $rubric->loadMissing('sections.criteria');
        $assignment->loadMissing('rubricSections');

        if ($assignment->rubricSections->isEmpty()) {
            return $rubric->sections;
        }

        $assignedSectionIds = $assignment->rubricSections->modelKeys();

        return $rubric->sections
            ->whereIn('id', $assignedSectionIds)
            ->values();
    }

    /** @return array{required: int, completed: int, missing: int, ready: bool} */
    public function scoreProgress(FestivalScoreSheet $sheet, FestivalJudgeAssignment $assignment): array
    {
        $sheet->loadMissing(['rubric.sections.criteria', 'scores']);
        $sections = $this->sectionsFor($assignment, $sheet->rubric);
        $criterionIds = $this->requiredCriteria($sections)->pluck('id')->all();
        $required = count($criterionIds);
        $completed = $sheet->scores
            ->whereIn('festival_rubric_criterion_id', $criterionIds)
            ->whereNotNull('score')
            ->count();

        return [
            'required' => $required,
            'completed' => $completed,
            'missing' => max(0, $required - $completed),
            'ready' => $completed === $required && $sections->contains(fn (FestivalRubricSection $section): bool => $section->criteria->isNotEmpty()),
        ];
    }

    /**
     * @param  Collection<int, FestivalRubricSection>  $sections
     * @return Collection<int, FestivalRubricCriterion>
     */
    public function requiredCriteria(Collection $sections): Collection
    {
        return $sections->whereStrict('contribution', FestivalRubricSectionContribution::Award)->flatMap->criteria;
    }

    /** @param Builder<FestivalScoreSheet> $sheets */
    public function filterByReadiness(Builder $sheets, bool $ready): Builder
    {
        $assignmentSections = DB::table('festival_judge_assignment_rubric_section')
            ->whereColumn('festival_judge_assignment_id', 'festival_score_sheets.festival_judge_assignment_id');
        $selectedSections = (clone $assignmentSections)
            ->whereColumn('festival_rubric_section_id', 'festival_rubric_sections.id');
        $sections = FestivalRubricSection::query()
            ->whereColumn('festival_rubric_id', 'festival_score_sheets.festival_rubric_id')
            ->where(fn (Builder $query) => $query->whereNotExists($assignmentSections)->orWhereExists($selectedSections));
        $assignedCriteria = (clone $sections)->has('criteria');
        $storedScore = FestivalCriterionScore::query()
            ->whereColumn('festival_score_sheet_id', 'festival_score_sheets.id')
            ->whereColumn('festival_rubric_criterion_id', 'festival_rubric_criteria.id')
            ->whereNotNull('score');
        $missingRequiredCriteria = $sections
            ->where('contribution', FestivalRubricSectionContribution::Award->value)
            ->whereHas('criteria', fn (Builder $query) => $query->whereNotExists($storedScore->toBase()));
        $complete = fn (Builder $query) => $query
            ->whereExists($assignedCriteria->toBase())
            ->whereNotExists($missingRequiredCriteria->toBase());

        return $ready ? $sheets->where($complete) : $sheets->whereNot($complete);
    }

    /**
     * @param  Collection<int, FestivalJudgeAssignment>  $assignments
     * @return Collection<int, FestivalRubricSection>
     */
    public function uncoveredSections(FestivalRubric $rubric, Collection $assignments): Collection
    {
        $coveredSectionIds = $assignments
            ->flatMap(fn (FestivalJudgeAssignment $assignment): Collection => $this->sectionsFor($assignment, $rubric))
            ->pluck('id')
            ->all();

        return $rubric->sections
            ->whereNotIn('id', $coveredSectionIds)
            ->values();
    }
}
