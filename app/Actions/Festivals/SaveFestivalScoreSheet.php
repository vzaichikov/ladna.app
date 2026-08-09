<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalScoreSheetStatus;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalScoreSheet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveFestivalScoreSheet
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /** @param array<string, mixed> $input */
    public function execute(FestivalScoreSheet $sheet, FestivalJudgeAssignment $assignment, array $input, User|FestivalPortalUser $actor): FestivalScoreSheet
    {
        return DB::transaction(function () use ($sheet, $assignment, $input, $actor): FestivalScoreSheet {
            $sheet = FestivalScoreSheet::query()->with(['rubric.sections.criteria', 'entry.edition'])->whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            abort_unless($sheet->festival_judge_assignment_id === $assignment->id && $assignment->is_active, 403);
            abort_if($sheet->status === FestivalScoreSheetStatus::Locked, 409, __('app.festival_score_sheet_locked'));

            if ($sheet->lock_version !== (int) $input['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => __('app.festival_score_sheet_changed')]);
            }

            $criteria = $sheet->rubric->sections
                ->flatMap(fn ($section) => $section->criteria->each(fn ($criterion) => $criterion->setRelation('section', $section)))
                ->keyBy('id');
            $submittedIds = collect($input['scores'])->pluck('criterion_id');
            if ($submittedIds->sort()->values()->all() !== $criteria->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['scores' => __('app.festival_scores_incomplete')]);
            }

            $total = 0.0;
            foreach ($input['scores'] as $row) {
                /** @var FestivalRubricCriterion $criterion */
                $criterion = $criteria[(int) $row['criterion_id']];
                if ((float) $row['score'] > (float) $criterion->max_score) {
                    throw ValidationException::withMessages(['scores' => __('app.festival_score_above_maximum')]);
                }
                $sheet->scores()->updateOrCreate(
                    ['festival_rubric_criterion_id' => $criterion->id],
                    ['account_id' => $sheet->account_id, 'score' => $row['score'], 'comment' => $row['comment'] ?? null],
                );
                $total += (float) $row['score'] * (float) $criterion->weight * (float) $criterion->section->weight;
            }

            $submitted = (bool) ($input['submit'] ?? false);
            $sheet->forceFill([
                'comments' => $input['comments'] ?? null,
                'total_score' => round($total, 4),
                'lock_version' => $sheet->lock_version + 1,
                'status' => $submitted ? FestivalScoreSheetStatus::Locked : FestivalScoreSheetStatus::Draft,
                'submitted_at' => $submitted ? now() : null,
                'locked_at' => $submitted ? now() : null,
            ])->save();
            $sheet->rubric->forceFill(['locked_at' => $sheet->rubric->locked_at ?? now()])->save();
            $this->activity->record($sheet, $submitted ? 'score_sheet.submitted' : 'score_sheet.saved', $sheet->entry->edition, $actor, ['total' => $sheet->total_score]);

            return $sheet->refresh()->load('scores');
        }, 3);
    }
}
