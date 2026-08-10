<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('festival_editions')->orderBy('id')->get() as $edition) {
                $workflowId = DB::table('festival_workflows')->insertGetId([
                    'account_id' => $edition->account_id,
                    'festival_edition_id' => $edition->id,
                    'name' => 'Standard registration',
                    'version' => 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $steps = [
                    ['code' => 'application', 'type' => 'application', 'title' => 'Application and qualification', 'review_mode' => 'organizer', 'review_effect' => 'qualification', 'sort_order' => 10],
                    ['code' => 'participation_payment', 'type' => 'payment', 'title' => 'Participation payment', 'review_mode' => 'automatic', 'review_effect' => 'none', 'sort_order' => 20],
                    ['code' => 'technical_form', 'type' => 'form', 'title' => 'Technical form', 'review_mode' => 'organizer', 'review_effect' => 'none', 'sort_order' => 30],
                    ['code' => 'summary', 'type' => 'summary', 'title' => 'Summary', 'review_mode' => 'automatic', 'review_effect' => 'none', 'sort_order' => 40],
                ];

                $stepIds = [];
                foreach ($steps as $step) {
                    $stepIds[$step['code']] = DB::table('festival_workflow_steps')->insertGetId([
                        'account_id' => $edition->account_id,
                        'festival_workflow_id' => $workflowId,
                        ...$step,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('festival_categories')->where('festival_edition_id', $edition->id)->update(['festival_workflow_id' => $workflowId]);
                DB::table('festival_requirement_definitions')->where('festival_edition_id', $edition->id)->where('stage', 'qualification')->update(['festival_workflow_step_id' => $stepIds['application']]);
                DB::table('festival_requirement_definitions')->where('festival_edition_id', $edition->id)->where('stage', '!=', 'qualification')->update(['festival_workflow_step_id' => $stepIds['technical_form']]);
                DB::table('festival_charge_definitions')->where('festival_edition_id', $edition->id)->where('kind', 'qualification')->update(['festival_workflow_step_id' => $stepIds['application']]);
                DB::table('festival_charge_definitions')->where('festival_edition_id', $edition->id)->where('kind', 'participation')->update(['festival_workflow_step_id' => $stepIds['participation_payment']]);
                DB::table('festival_charge_definitions')->where('festival_edition_id', $edition->id)->whereNotIn('kind', ['qualification', 'participation'])->update(['festival_workflow_step_id' => $stepIds['technical_form']]);

                foreach (DB::table('festival_entries')->where('festival_edition_id', $edition->id)->orderBy('id')->get() as $entry) {
                    $terminal = in_array($entry->status, ['accepted', 'rejected', 'withdrawn'], true);
                    $entryStepIds = [];

                    foreach ($steps as $index => $step) {
                        $status = $terminal ? 'approved' : ($index === 0 && $entry->status !== 'draft' ? 'submitted' : 'draft');
                        if ($entry->status === 'rejected' && $index === 0) {
                            $status = 'rejected';
                        }

                        $entryStepIds[$step['code']] = DB::table('festival_entry_steps')->insertGetId([
                            'account_id' => $edition->account_id,
                            'festival_entry_id' => $entry->id,
                            'festival_workflow_step_id' => $stepIds[$step['code']],
                            ...$step,
                            'status' => $status,
                            'submitted_at' => $status !== 'draft' ? ($entry->submitted_at ?? $entry->updated_at) : null,
                            'reviewed_at' => $status === 'approved' ? ($entry->reviewed_at ?? $entry->updated_at) : null,
                            'step_snapshot' => json_encode($step, JSON_THROW_ON_ERROR),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('festival_entries')->where('id', $entry->id)->update([
                        'workflow_snapshot' => json_encode(['workflow_id' => $workflowId, 'name' => 'Standard registration', 'version' => 1, 'steps' => $steps], JSON_THROW_ON_ERROR),
                        'registration_completed_at' => $entry->status === 'accepted' ? ($entry->accepted_at ?? $entry->updated_at) : null,
                    ]);
                    DB::table('festival_entry_requirements')->where('festival_entry_id', $entry->id)->update(['festival_entry_step_id' => $entryStepIds['technical_form'], 'is_required' => true]);
                    DB::table('festival_charges')->where('festival_entry_id', $entry->id)->where('kind', 'qualification')->update(['festival_entry_step_id' => $entryStepIds['application']]);
                    DB::table('festival_charges')->where('festival_entry_id', $entry->id)->where('kind', 'participation')->update(['festival_entry_step_id' => $entryStepIds['participation_payment']]);
                    DB::table('festival_charges')->where('festival_entry_id', $entry->id)->whereNotIn('kind', ['qualification', 'participation'])->update(['festival_entry_step_id' => $entryStepIds['technical_form']]);
                }
            }
        }, 3);
    }

    public function down(): void
    {
        // Forward-only data backfill. Structural rollback is handled by the preceding migration.
    }
};
