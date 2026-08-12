<?php

namespace App\Support\Festivals;

use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricSection;
use Illuminate\Support\Collection;

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
