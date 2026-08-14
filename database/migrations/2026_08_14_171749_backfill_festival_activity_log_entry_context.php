<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->backfillEntrySubjects();

        foreach ([
            'App\\Models\\FestivalEntryStep' => 'festival_entry_steps',
            'App\\Models\\FestivalEntryRequirement' => 'festival_entry_requirements',
            'App\\Models\\FestivalSubmission' => 'festival_submissions',
            'App\\Models\\FestivalCharge' => 'festival_charges',
            'App\\Models\\FestivalScheduleSlot' => 'festival_schedule_slots',
            'App\\Models\\FestivalScoreSheet' => 'festival_score_sheets',
            'App\\Models\\FestivalTimelineItem' => 'festival_timeline_items',
        ] as $subjectType => $subjectTable) {
            $this->backfillDirectEntryRelation($subjectType, $subjectTable);
        }

        $this->backfillPaymentAttempts();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The schema migration removes the backfilled column during a rollback.
    }

    private function backfillEntrySubjects(): void
    {
        $query = DB::table('festival_activity_logs as activity_logs')
            ->join('festival_entries as entries', 'entries.id', '=', 'activity_logs.subject_id')
            ->where('activity_logs.subject_type', 'App\\Models\\FestivalEntry')
            ->whereNull('activity_logs.festival_entry_id')
            ->whereColumn('activity_logs.account_id', 'entries.account_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('activity_logs.festival_edition_id')
                ->orWhereColumn('activity_logs.festival_edition_id', 'entries.festival_edition_id'));

        $this->updateFromQuery($query);
    }

    private function backfillDirectEntryRelation(string $subjectType, string $subjectTable): void
    {
        $query = DB::table('festival_activity_logs as activity_logs')
            ->join($subjectTable.' as subjects', 'subjects.id', '=', 'activity_logs.subject_id')
            ->join('festival_entries as entries', 'entries.id', '=', 'subjects.festival_entry_id')
            ->where('activity_logs.subject_type', $subjectType)
            ->whereNull('activity_logs.festival_entry_id')
            ->whereColumn('activity_logs.account_id', 'entries.account_id')
            ->whereColumn('subjects.account_id', 'entries.account_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('activity_logs.festival_edition_id')
                ->orWhereColumn('activity_logs.festival_edition_id', 'entries.festival_edition_id'));

        $this->updateFromQuery($query);
    }

    private function backfillPaymentAttempts(): void
    {
        $query = DB::table('festival_activity_logs as activity_logs')
            ->join('festival_payment_attempts as attempts', 'attempts.id', '=', 'activity_logs.subject_id')
            ->join('festival_charges as charges', 'charges.id', '=', 'attempts.festival_charge_id')
            ->join('festival_entries as entries', 'entries.id', '=', 'charges.festival_entry_id')
            ->where('activity_logs.subject_type', 'App\\Models\\FestivalPaymentAttempt')
            ->whereNull('activity_logs.festival_entry_id')
            ->whereColumn('activity_logs.account_id', 'entries.account_id')
            ->whereColumn('attempts.account_id', 'entries.account_id')
            ->whereColumn('charges.account_id', 'entries.account_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('activity_logs.festival_edition_id')
                ->orWhereColumn('activity_logs.festival_edition_id', 'entries.festival_edition_id'));

        $this->updateFromQuery($query);
    }

    private function updateFromQuery(Builder $query): void
    {
        $query
            ->select(['activity_logs.id as activity_log_id', 'entries.id as festival_entry_id'])
            ->orderBy('activity_logs.id')
            ->chunkById(200, function (Collection $rows): void {
                $rows->groupBy('festival_entry_id')->each(function (Collection $entryRows, int|string $entryId): void {
                    DB::table('festival_activity_logs')
                        ->whereNull('festival_entry_id')
                        ->whereIn('id', $entryRows->pluck('activity_log_id'))
                        ->update(['festival_entry_id' => (int) $entryId]);
                });
            }, 'activity_logs.id', 'activity_log_id');
    }
};
