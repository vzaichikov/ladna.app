<?php

namespace App\Actions\Festivals;

use App\Models\FestivalEdition;
use App\Models\FestivalWorkflow;
use Illuminate\Support\Facades\DB;

class ProvisionFestivalWorkflow
{
    /**
     * @param  array<int, array<string, mixed>>|null  $steps
     */
    public function execute(FestivalEdition $edition, string $name, ?array $steps = null): FestivalWorkflow
    {
        return DB::transaction(function () use ($edition, $name, $steps): FestivalWorkflow {
            $workflow = FestivalWorkflow::query()
                ->where('festival_edition_id', $edition->id)
                ->where('name', $name)
                ->lockForUpdate()
                ->first();

            if ($workflow) {
                return $workflow->load('steps');
            }

            $workflow = FestivalWorkflow::query()->create(['account_id' => $edition->account_id, 'festival_edition_id' => $edition->id, 'name' => $name]);

            foreach ($steps ?? $this->standardSteps() as $step) {
                $workflow->steps()->create(['account_id' => $edition->account_id, ...$step]);
            }

            return $workflow->load('steps');
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function standardSteps(string $applicationReviewMode = 'organizer', string $technicalReviewMode = 'organizer'): array
    {
        return [
            ['code' => 'application', 'type' => 'application', 'title' => __('app.festival_step_application'), 'description' => __('app.festival_step_application_help'), 'sort_order' => 10, 'review_mode' => $applicationReviewMode, 'review_effect' => $applicationReviewMode === 'organizer' ? 'qualification' : 'none'],
            ['code' => 'participation_payment', 'type' => 'payment', 'title' => __('app.festival_step_payment'), 'description' => __('app.festival_step_payment_help'), 'sort_order' => 20, 'review_mode' => 'automatic', 'review_effect' => 'none'],
            ['code' => 'technical_form', 'type' => 'form', 'title' => __('app.festival_step_technical'), 'description' => __('app.festival_step_technical_help'), 'sort_order' => 30, 'review_mode' => $technicalReviewMode, 'review_effect' => 'none'],
            ['code' => 'summary', 'type' => 'summary', 'title' => __('app.festival_step_summary'), 'description' => __('app.festival_step_summary_help'), 'sort_order' => 40, 'review_mode' => 'automatic', 'review_effect' => 'none'],
        ];
    }
}
