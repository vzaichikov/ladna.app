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
            $wasReady = $sheet->status === FestivalScoreSheetStatus::Submitted;
            $sections = $this->judgingCriteria->sectionsFor($assignment, $sheet->rubric);
            $criteria = $sections
                ->flatMap(fn ($section) => $section->criteria->each(fn ($criterion) => $criterion->setRelation('section', $section)))
                ->keyBy('id');
            $submittedRows = collect($input['scores'] ?? []);
            $submittedIds = $submittedRows->pluck('criterion_id')->map(fn (mixed $criterionId): int => (int) $criterionId);
            if ($submittedIds->diff($criteria->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['scores' => __('app.festival_score_criterion_invalid')]);
            }

            foreach ($submittedRows as $row) {
                /** @var FestivalRubricCriterion $criterion */
                $criterion = $criteria[(int) $row['criterion_id']];
                $storedScore = $sheet->scores()
                    ->where('festival_rubric_criterion_id', $criterion->id)
                    ->lockForUpdate()
                    ->first();
                $score = array_key_exists('score', $row) ? $row['score'] : $storedScore?->score;
                $comment = array_key_exists('comment', $row)
                    ? (filled($row['comment']) ? (string) $row['comment'] : null)
                    : $storedScore?->comment;

                if ($score === null || $score === '') {
                    if ($comment === null) {
                        $storedScore?->delete();
                    } else {
                        $sheet->scores()->updateOrCreate(
                            ['festival_rubric_criterion_id' => $criterion->id],
                            ['account_id' => $sheet->account_id, 'score' => null, 'comment' => $comment],
                        );
                    }

                    continue;
                }

                if (bccomp((string) $score, '0', 8) === -1 || bccomp((string) $score, (string) $criterion->max_score, 8) === 1) {
                    throw ValidationException::withMessages(['scores' => __('app.festival_score_above_maximum')]);
                }
                $sheet->scores()->updateOrCreate(
                    ['festival_rubric_criterion_id' => $criterion->id],
                    ['account_id' => $sheet->account_id, 'score' => $score, 'comment' => $comment],
                );
            }

            $storedScores = $sheet->scores()
                ->whereIn('festival_rubric_criterion_id', $criteria->keys())
                ->get()
                ->keyBy('festival_rubric_criterion_id');
            $total = '0.00000000';
            $completed = 0;
            foreach ($criteria as $criterion) {
                $score = $storedScores->get($criterion->id)?->score;

                if ($score === null || $score === '') {
                    continue;
                }

                $completed++;
                $weightedScore = bcmul(
                    bcmul((string) $score, (string) $criterion->weight, 8),
                    (string) $criterion->section->weight,
                    8,
                );
                $total = $criterion->section->contribution === FestivalRubricSectionContribution::Deduction
                    ? bcsub($total, $weightedScore, 8)
                    : bcadd($total, $weightedScore, 8);
            }

            $ready = $criteria->isNotEmpty() && $completed === $criteria->count();
            $sheetAttributes = [
                'total_score' => $this->round($total),
                'status' => $ready ? FestivalScoreSheetStatus::Submitted : FestivalScoreSheetStatus::Draft,
                'submitted_at' => $ready ? ($sheet->submitted_at ?? now()) : null,
            ];
            if (array_key_exists('comments', $input)) {
                $sheetAttributes['comments'] = $input['comments'];
            }
            $sheet->forceFill($sheetAttributes)->save();
            FestivalResult::query()
                ->whereIn('festival_entry_id', FestivalEntry::query()
                    ->select('id')
                    ->where('festival_edition_id', $sheet->entry->festival_edition_id)
                    ->where('festival_category_id', $sheet->entry->festival_category_id))
                ->delete();
            $this->activity->record($sheet, $ready && ! $wasReady ? 'score_sheet.ready' : 'score_sheet.saved', $sheet->entry->edition, $actor, ['total' => $sheet->total_score]);

            return $sheet->refresh()->load('scores');
        }, 3);
    }

    private function round(string $value): string
    {
        $increment = bccomp($value, '0', 8) >= 0 ? '0.00005' : '-0.00005';

        return bcadd($value, $increment, 4);
    }
}
