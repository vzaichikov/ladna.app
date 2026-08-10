<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('festival_editions')->orderBy('id')->get() as $edition) {
                $standardWorkflowId = DB::table('festival_workflows')
                    ->where('festival_edition_id', $edition->id)
                    ->where('name', 'Standard registration')
                    ->orderByDesc('version')
                    ->value('id');

                foreach (['direct', 'review'] as $legacyWorkflow) {
                    if (! DB::table('festival_categories')->where('festival_edition_id', $edition->id)->where('workflow', $legacyWorkflow)->exists()) {
                        continue;
                    }

                    $name = $legacyWorkflow === 'direct' ? 'Direct registration' : 'Organizer review';
                    $workflowId = DB::table('festival_workflows')->insertGetId([
                        'account_id' => $edition->account_id,
                        'festival_edition_id' => $edition->id,
                        'name' => $name,
                        'version' => 1,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $automatic = $legacyWorkflow === 'direct';
                    foreach ($this->steps($automatic) as $step) {
                        DB::table('festival_workflow_steps')->insert([
                            'account_id' => $edition->account_id,
                            'festival_workflow_id' => $workflowId,
                            ...$step,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('festival_categories')
                        ->where('festival_edition_id', $edition->id)
                        ->where('workflow', $legacyWorkflow)
                        ->update(['festival_workflow_id' => $workflowId]);
                }

                if ($standardWorkflowId) {
                    DB::table('festival_categories')
                        ->where('festival_edition_id', $edition->id)
                        ->where('workflow', 'qualification')
                        ->update(['festival_workflow_id' => $standardWorkflowId]);
                }
            }
        }, 3);
    }

    public function down(): void
    {
        // Forward-only compatibility backfill. Existing entry snapshots must remain immutable.
    }

    /** @return array<int, array<string, mixed>> */
    private function steps(bool $automatic): array
    {
        return [
            ['code' => 'application', 'type' => 'application', 'title' => 'Application', 'sort_order' => 10, 'review_mode' => $automatic ? 'automatic' : 'organizer', 'review_effect' => 'none'],
            ['code' => 'participation_payment', 'type' => 'payment', 'title' => 'Participation payment', 'sort_order' => 20, 'review_mode' => 'automatic', 'review_effect' => 'none'],
            ['code' => 'technical_form', 'type' => 'form', 'title' => 'Technical form', 'sort_order' => 30, 'review_mode' => $automatic ? 'automatic' : 'organizer', 'review_effect' => 'none'],
            ['code' => 'summary', 'type' => 'summary', 'title' => 'Summary', 'sort_order' => 40, 'review_mode' => 'automatic', 'review_effect' => 'none'],
        ];
    }
};
