<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalRubricSectionContribution;
use App\Enums\FestivalScoreSheetStatus;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalResult;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalScoreSheet;
use App\Models\User;
use App\Support\Festivals\FestivalJudgingCriteria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveFestivalScoreSheet
{
    public function __construct(
        private readonly FestivalActivityRecorder $activity,
        private readonly FestivalJudgingCriteria $judgingCriteria,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(FestivalScoreSheet $sheet, FestivalJudgeAssignment $assignment, array $input, User|FestivalPortalUser $actor): FestivalScoreSheet
    {
        return DB::transaction(function () use ($sheet, $assignment, $input, $actor): FestivalScoreSheet {
            $sheet = FestivalScoreSheet::query()->with(['rubric.sections.criteria', 'entry.edition'])->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $assignment = FestivalJudgeAssignment::query()->with('rubricSections')->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($sheet->festival_judge_assignment_id === $assignment->id && $assignment->is_active, 403);
            if ($sheet->status === FestivalScoreSheetStatus::Submitted) {
                throw ValidationException::withMessages(['scores' => __('app.festival_score_sheet_locked')]);
            }
            $sections = $this->judgingCriteria->sectionsFor($assignment, $sheet->rubric);
            $criteria = $sections
                ->flatMap(fn ($section) => $section->criteria->each(fn ($criterion) => $criterion->setRelation('section', $section)))
                ->keyBy('id');
            $submittedIds = collect($input['scores'])->pluck('criterion_id');
            if ($submittedIds->sort()->values()->all() !== $criteria->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['scores' => __('app.festival_scores_incomplete')]);
            }

            $total = '0.00000000';
            foreach ($input['scores'] as $row) {
                /** @var FestivalRubricCriterion $criterion */
                $criterion = $criteria[(int) $row['criterion_id']];
                if (bccomp((string) $row['score'], '0', 2) === -1 || bccomp((string) $row['score'], (string) $criterion->max_score, 2) === 1) {
                    throw ValidationException::withMessages(['scores' => __('app.festival_score_above_maximum')]);
                }
                $sheet->scores()->updateOrCreate(
                    ['festival_rubric_criterion_id' => $criterion->id],
                    ['account_id' => $sheet->account_id, 'score' => $row['score'], 'comment' => $row['comment'] ?? null],
                );
                $weightedScore = bcmul(
                    bcmul((string) $row['score'], (string) $criterion->weight, 8),
                    (string) $criterion->section->weight,
                    8,
                );
                $total = $criterion->section->contribution === FestivalRubricSectionContribution::Deduction
                    ? bcsub($total, $weightedScore, 8)
                    : bcadd($total, $weightedScore, 8);
            }

            $submitted = (bool) ($input['submit'] ?? false);
            $sheet->forceFill([
                'comments' => $input['comments'] ?? null,
                'total_score' => $this->round($total),
                'status' => $submitted ? FestivalScoreSheetStatus::Submitted : FestivalScoreSheetStatus::Draft,
                'submitted_at' => $submitted ? now() : null,
            ])->save();
            FestivalResult::query()
                ->whereIn('festival_entry_id', FestivalEntry::query()
                    ->select('id')
                    ->where('festival_edition_id', $sheet->entry->festival_edition_id)
                    ->where('festival_category_id', $sheet->entry->festival_category_id))
                ->delete();
            $this->activity->record($sheet, $submitted ? 'score_sheet.submitted' : 'score_sheet.saved', $sheet->entry->edition, $actor, ['total' => $sheet->total_score]);

            return $sheet->refresh()->load('scores');
        }, 3);
    }

    private function round(string $value): string
    {
        $increment = bccomp($value, '0', 8) >= 0 ? '0.00005' : '-0.00005';

        return bcadd($value, $increment, 4);
    }
}
