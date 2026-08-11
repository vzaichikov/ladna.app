<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertContractIsSafe();

        $indexes = collect(Schema::getIndexes('festival_entry_steps'));
        $relationalUniqueExists = $indexes->contains(fn (array $index): bool => (bool) $index['unique']
            && $index['columns'] === ['festival_entry_id', 'festival_workflow_step_id']);

        if (! $relationalUniqueExists) {
            Schema::table('festival_entry_steps', function (Blueprint $table): void {
                $table->unique(
                    ['festival_entry_id', 'festival_workflow_step_id'],
                    'festival_entry_workflow_step_unique',
                );
            });
        }

        $workflowStepForeignKey = collect(Schema::getForeignKeys('festival_entry_steps'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['festival_workflow_step_id']);

        if ($workflowStepForeignKey) {
            Schema::table('festival_entry_steps', function (Blueprint $table) use ($workflowStepForeignKey): void {
                $table->dropForeign($workflowStepForeignKey['name']);
            });
        }

        $indexes = collect(Schema::getIndexes('festival_entry_steps'));
        $legacyCodeIndex = $indexes->first(fn (array $index): bool => $index['columns'] === ['festival_entry_id', 'code']);
        $legacySortIndex = $indexes->first(fn (array $index): bool => $index['columns'] === ['festival_entry_id', 'sort_order']);

        foreach (array_filter([$legacyCodeIndex, $legacySortIndex]) as $legacyIndex) {
            Schema::table('festival_entry_steps', function (Blueprint $table) use ($legacyIndex): void {
                $table->dropIndex($legacyIndex['name']);
            });
        }

        $workflowStepColumn = collect(Schema::getColumns('festival_entry_steps'))
            ->firstWhere('name', 'festival_workflow_step_id');

        if ($workflowStepColumn['nullable']) {
            Schema::table('festival_entry_steps', function (Blueprint $table): void {
                $table->unsignedBigInteger('festival_workflow_step_id')->nullable(false)->change();
            });
        }

        $workflowStepForeignKey = collect(Schema::getForeignKeys('festival_entry_steps'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['festival_workflow_step_id']);

        if (! $workflowStepForeignKey) {
            Schema::table('festival_entry_steps', function (Blueprint $table): void {
                $table->foreign('festival_workflow_step_id')
                    ->references('id')
                    ->on('festival_workflow_steps')
                    ->restrictOnDelete();
            });
        }

        $this->dropColumnsIfPresent('festival_entry_steps', [
            'code',
            'type',
            'title',
            'description',
            'sort_order',
            'review_mode',
            'review_effect',
            'opens_at',
            'due_at',
            'revision_due_at',
            'step_snapshot',
        ]);
        $this->dropColumnsIfPresent('festival_entry_requirements', ['subject_scope', 'definition_snapshot', 'is_required', 'due_at']);
        $this->dropColumnsIfPresent('festival_entry_participant', ['age_snapshot', 'name_snapshot', 'participant_snapshot']);
        $this->dropColumnsIfPresent('festival_entries', [
            'coach_name_snapshot',
            'studio_name_snapshot',
            'category_snapshot',
            'registrant_snapshot',
            'workflow_snapshot',
        ]);
    }

    /**
     * Removed copied Festival configuration cannot be reconstructed.
     */
    public function down(): void {}

    /** @param array<int, string> $columns */
    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }

    private function assertContractIsSafe(): void
    {
        $invalidEntries = DB::table('festival_entries as entries')
            ->leftJoin('festival_editions as editions', 'editions.id', '=', 'entries.festival_edition_id')
            ->leftJoin('festival_portal_users as portal_users', 'portal_users.id', '=', 'entries.festival_portal_user_id')
            ->leftJoin('festival_categories as categories', 'categories.id', '=', 'entries.festival_category_id')
            ->where(function ($query): void {
                $query->whereNull('editions.id')
                    ->orWhereNull('portal_users.id')
                    ->orWhereNull('categories.id')
                    ->orWhereColumn('editions.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('portal_users.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('categories.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('categories.festival_edition_id', '!=', 'entries.festival_edition_id');
            })
            ->exists();

        if ($invalidEntries) {
            throw new RuntimeException('Festival relational cleanup stopped: an entry has an invalid account-, edition-, category-, or registrant relationship.');
        }

        $invalidParticipants = DB::table('festival_entry_participant as entry_participants')
            ->leftJoin('festival_entries as entries', 'entries.id', '=', 'entry_participants.festival_entry_id')
            ->leftJoin('festival_participants as participants', 'participants.id', '=', 'entry_participants.festival_participant_id')
            ->where(function ($query): void {
                $query->whereNull('entries.id')
                    ->orWhereNull('participants.id')
                    ->orWhereColumn('entry_participants.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('participants.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('participants.festival_portal_user_id', '!=', 'entries.festival_portal_user_id');
            })
            ->exists();

        if ($invalidParticipants) {
            throw new RuntimeException('Festival relational cleanup stopped: an entry participant crosses an account or registrant boundary, or has no current participant.');
        }

        $invalidSteps = DB::table('festival_entry_steps as entry_steps')
            ->join('festival_entries as entries', 'entries.id', '=', 'entry_steps.festival_entry_id')
            ->leftJoin('festival_workflow_steps as workflow_steps', 'workflow_steps.id', '=', 'entry_steps.festival_workflow_step_id')
            ->leftJoin('festival_workflows as workflows', 'workflows.id', '=', 'workflow_steps.festival_workflow_id')
            ->where(function ($query): void {
                $query->whereNull('entry_steps.festival_workflow_step_id')
                    ->orWhereNull('workflow_steps.id')
                    ->orWhereNull('workflows.id')
                    ->orWhereColumn('entry_steps.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('workflow_steps.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('workflows.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('workflows.festival_edition_id', '!=', 'entries.festival_edition_id');
            })
            ->exists();

        if ($invalidSteps) {
            throw new RuntimeException('Festival relational cleanup stopped: an entry step has no valid account- and edition-scoped workflow step.');
        }

        $duplicateSteps = DB::table('festival_entry_steps')
            ->select(['festival_entry_id', 'festival_workflow_step_id'])
            ->groupBy(['festival_entry_id', 'festival_workflow_step_id'])
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($duplicateSteps) {
            throw new RuntimeException('Festival relational cleanup stopped: an entry has duplicate progress rows for one workflow step.');
        }

        $invalidRequirements = DB::table('festival_entry_requirements as entry_requirements')
            ->join('festival_entries as entries', 'entries.id', '=', 'entry_requirements.festival_entry_id')
            ->leftJoin('festival_requirement_definitions as definitions', 'definitions.id', '=', 'entry_requirements.festival_requirement_definition_id')
            ->leftJoin('festival_entry_steps as entry_steps', 'entry_steps.id', '=', 'entry_requirements.festival_entry_step_id')
            ->leftJoin('festival_participants as participants', 'participants.id', '=', 'entry_requirements.festival_participant_id')
            ->leftJoin('festival_entry_participant as requirement_participants', function (JoinClause $join): void {
                $join->on('requirement_participants.festival_entry_id', '=', 'entry_requirements.festival_entry_id')
                    ->on('requirement_participants.festival_participant_id', '=', 'entry_requirements.festival_participant_id');
            })
            ->where(function ($query): void {
                $query->whereNull('definitions.id')
                    ->orWhereColumn('entry_requirements.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('definitions.account_id', '!=', 'entries.account_id')
                    ->orWhereColumn('definitions.festival_edition_id', '!=', 'entries.festival_edition_id')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('entry_requirements.festival_entry_step_id')
                            ->where(function ($query): void {
                                $query->whereNull('entry_steps.id')
                                    ->orWhereColumn('entry_steps.festival_entry_id', '!=', 'entries.id');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('entry_requirements.festival_participant_id')
                            ->where(function ($query): void {
                                $query->whereNull('participants.id')
                                    ->orWhereColumn('participants.account_id', '!=', 'entries.account_id')
                                    ->orWhereNull('requirement_participants.id');
                            });
                    });
            })
            ->exists();

        if ($invalidRequirements) {
            throw new RuntimeException('Festival relational cleanup stopped: an entry requirement has an invalid current definition, step, or participant relationship.');
        }
    }
};
