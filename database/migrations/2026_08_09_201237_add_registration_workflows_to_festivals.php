<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('festival_workflows')) {
            Schema::create('festival_workflows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_active')->default(true);
                $table->timestamp('locked_at')->nullable();
                $table->timestamps();
                $table->unique(['festival_edition_id', 'name', 'version'], 'festival_workflow_version_unique');
                $table->index(['festival_edition_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('festival_workflow_steps')) {
            Schema::create('festival_workflow_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_workflow_id')->constrained()->cascadeOnDelete();
                $table->string('code');
                $table->string('type');
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('review_mode')->default('automatic');
                $table->string('review_effect')->default('none');
                $table->timestamp('opens_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->json('config')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('locked_at')->nullable();
                $table->timestamps();
                $table->unique(['festival_workflow_id', 'code'], 'festival_workflow_step_code_unique');
                $table->index(['festival_workflow_id', 'sort_order']);
            });
        }

        if (! Schema::hasColumn('festival_editions', 'max_entries_per_participant')) {
            Schema::table('festival_editions', function (Blueprint $table) {
                $table->unsignedSmallInteger('max_entries_per_participant')->nullable()->after('registration_closes_at');
            });
        }

        if (! Schema::hasColumn('festival_categories', 'festival_workflow_id')) {
            Schema::table('festival_categories', function (Blueprint $table) {
                $table->foreignId('festival_workflow_id')->nullable()->after('festival_edition_id')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasColumn('festival_entries', 'performer_name')) {
            Schema::table('festival_entries', function (Blueprint $table) {
                $table->renameColumn('performer_name', 'entry_name');
            });
        }
        if (! Schema::hasColumn('festival_entries', 'registrant_snapshot')) {
            Schema::table('festival_entries', function (Blueprint $table) {
                $table->json('registrant_snapshot')->nullable()->after('category_snapshot');
                $table->json('workflow_snapshot')->nullable()->after('registrant_snapshot');
                $table->timestamp('registration_completed_at')->nullable()->after('accepted_at');
            });
        }

        if (! Schema::hasColumn('festival_entry_participant', 'participant_snapshot')) {
            Schema::table('festival_entry_participant', function (Blueprint $table) {
                $table->json('participant_snapshot')->nullable()->after('name_snapshot');
            });
        }

        if (! Schema::hasTable('festival_entry_steps')) {
            Schema::create('festival_entry_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_workflow_step_id')->nullable()->constrained()->nullOnDelete();
                $table->string('code');
                $table->string('type');
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('review_mode')->default('automatic');
                $table->string('review_effect')->default('none');
                $table->string('status')->default('draft')->index();
                $table->timestamp('opens_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('review_notes')->nullable();
                $table->timestamp('revision_due_at')->nullable();
                $table->json('step_snapshot')->nullable();
                $table->timestamps();
                $table->unique(['festival_entry_id', 'code'], 'festival_entry_step_code_unique');
                $table->index(['festival_entry_id', 'sort_order']);
            });
        }

        if (! Schema::hasColumn('festival_requirement_definitions', 'code')) {
            $requirementWorkflowColumnExists = Schema::hasColumn('festival_requirement_definitions', 'festival_workflow_step_id');
            Schema::table('festival_requirement_definitions', function (Blueprint $table) use ($requirementWorkflowColumnExists) {
                if (! $requirementWorkflowColumnExists) {
                    $table->unsignedBigInteger('festival_workflow_step_id')->nullable()->after('festival_category_id');
                }
                $table->string('code')->nullable()->after('festival_workflow_step_id');
                $table->string('subject_scope')->default('entry')->after('type');
                $table->string('input_type')->default('file')->after('subject_scope');
                $table->json('options')->nullable()->after('instructions');
                $table->json('validation')->nullable()->after('options');
                $table->json('pricing')->nullable()->after('validation');
                $table->boolean('is_active')->default(true)->after('is_required');
                $table->index(['festival_workflow_step_id', 'is_active'], 'festival_requirement_step_active_idx');
            });
            Schema::table('festival_requirement_definitions', function (Blueprint $table) {
                $table->foreign('festival_workflow_step_id', 'festival_req_workflow_step_fk')->references('id')->on('festival_workflow_steps')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('festival_charge_definitions', 'festival_workflow_step_id')) {
            Schema::table('festival_charge_definitions', function (Blueprint $table) {
                $table->foreignId('festival_workflow_step_id')->nullable()->after('festival_category_id')->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('festival_entry_requirements', 'festival_entry_step_id')) {
            Schema::table('festival_entry_requirements', function (Blueprint $table) {
                $table->index('festival_entry_id', 'festival_entry_requirements_entry_idx');
            });
            Schema::table('festival_entry_requirements', function (Blueprint $table) {
                $table->dropUnique('festival_entry_requirement_unique');
                $table->foreignId('festival_entry_step_id')->nullable()->after('festival_entry_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_participant_id')->nullable()->after('festival_requirement_definition_id')->constrained()->nullOnDelete();
                $table->string('subject_scope')->default('entry')->after('festival_participant_id');
                $table->string('subject_key')->default('entry')->after('subject_scope');
                $table->boolean('is_required')->default(true)->after('definition_snapshot');
                $table->unique(['festival_entry_id', 'festival_requirement_definition_id', 'subject_key'], 'festival_entry_requirement_subject_unique');
                $table->index(['festival_entry_step_id', 'status']);
            });
        }

        if (! Schema::hasColumn('festival_submissions', 'value_json')) {
            Schema::table('festival_submissions', function (Blueprint $table) {
                $table->string('disk')->nullable()->change();
                $table->string('path')->nullable()->change();
                $table->string('original_name')->nullable()->change();
                $table->string('mime_type', 150)->nullable()->change();
                $table->unsignedBigInteger('size_bytes')->nullable()->change();
                $table->json('value_json')->nullable()->after('duration_seconds');
            });
        }

        if (! Schema::hasColumn('festival_charges', 'festival_entry_step_id')) {
            Schema::table('festival_charges', function (Blueprint $table) {
                $table->foreignId('festival_entry_step_id')->nullable()->after('festival_entry_id')->constrained()->nullOnDelete();
                $table->foreignId('festival_entry_requirement_id')->nullable()->after('festival_charge_definition_id')->constrained()->nullOnDelete();
                $table->foreignId('festival_submission_id')->nullable()->after('festival_entry_requirement_id')->constrained()->nullOnDelete();
                $table->string('pricing_key')->nullable()->after('festival_submission_id');
                $table->index(['festival_entry_step_id', 'status']);
                $table->unique(['festival_entry_id', 'pricing_key'], 'festival_charge_pricing_key_unique');
            });
        }

        if (! Schema::hasTable('festival_charge_adjustments')) {
            Schema::create('festival_charge_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('festival_entry_step_id')->nullable();
                $table->unsignedBigInteger('festival_entry_requirement_id')->nullable();
                $table->unsignedBigInteger('festival_submission_id')->nullable();
                $table->unsignedBigInteger('festival_charge_id')->nullable();
                $table->string('idempotency_key')->unique();
                $table->string('direction');
                $table->string('status')->default('pending')->index();
                $table->unsignedBigInteger('amount_cents');
                $table->char('currency', 3);
                $table->json('snapshot')->nullable();
                $table->timestamps();
                $table->index(['festival_entry_id', 'direction', 'status'], 'festival_adjustments_entry_idx');
                $table->foreign('festival_entry_step_id', 'festival_adj_entry_step_fk')->references('id')->on('festival_entry_steps')->nullOnDelete();
                $table->foreign('festival_entry_requirement_id', 'festival_adj_requirement_fk')->references('id')->on('festival_entry_requirements')->nullOnDelete();
                $table->foreign('festival_submission_id', 'festival_adj_submission_fk')->references('id')->on('festival_submissions')->nullOnDelete();
                $table->foreign('festival_charge_id', 'festival_adj_charge_fk')->references('id')->on('festival_charges')->nullOnDelete();
            });
        } else {
            Schema::table('festival_charge_adjustments', function (Blueprint $table) {
                $table->unique('idempotency_key');
                $table->index('status');
                $table->index(['festival_entry_id', 'direction', 'status'], 'festival_adjustments_entry_idx');
                $table->foreign('festival_entry_requirement_id', 'festival_adj_requirement_fk')->references('id')->on('festival_entry_requirements')->nullOnDelete();
                $table->foreign('festival_submission_id', 'festival_adj_submission_fk')->references('id')->on('festival_submissions')->nullOnDelete();
                $table->foreign('festival_charge_id', 'festival_adj_charge_fk')->references('id')->on('festival_charges')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('festival_charge_adjustments');

        Schema::table('festival_charges', function (Blueprint $table) {
            $table->dropUnique('festival_charge_pricing_key_unique');
            $table->dropConstrainedForeignId('festival_submission_id');
            $table->dropConstrainedForeignId('festival_entry_requirement_id');
            $table->dropConstrainedForeignId('festival_entry_step_id');
            $table->dropColumn('pricing_key');
        });

        Schema::table('festival_submissions', function (Blueprint $table) {
            $table->dropColumn('value_json');
        });

        Schema::table('festival_entry_requirements', function (Blueprint $table) {
            $table->dropUnique('festival_entry_requirement_subject_unique');
            $table->dropConstrainedForeignId('festival_participant_id');
            $table->dropConstrainedForeignId('festival_entry_step_id');
            $table->dropColumn(['subject_scope', 'subject_key', 'is_required']);
            $table->unique(['festival_entry_id', 'festival_requirement_definition_id'], 'festival_entry_requirement_unique');
        });

        Schema::table('festival_charge_definitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('festival_workflow_step_id');
        });

        Schema::table('festival_requirement_definitions', function (Blueprint $table) {
            $table->dropIndex('festival_requirement_step_active_idx');
            $table->dropConstrainedForeignId('festival_workflow_step_id');
            $table->dropColumn(['code', 'subject_scope', 'input_type', 'options', 'validation', 'pricing', 'is_active']);
        });

        Schema::dropIfExists('festival_entry_steps');

        Schema::table('festival_entry_participant', function (Blueprint $table) {
            $table->dropColumn('participant_snapshot');
        });

        Schema::table('festival_entries', function (Blueprint $table) {
            $table->renameColumn('entry_name', 'performer_name');
            $table->dropColumn(['registrant_snapshot', 'workflow_snapshot', 'registration_completed_at']);
        });

        Schema::table('festival_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('festival_workflow_id');
        });

        Schema::table('festival_editions', function (Blueprint $table) {
            $table->dropColumn('max_entries_per_participant');
        });

        Schema::dropIfExists('festival_workflow_steps');
        Schema::dropIfExists('festival_workflows');
    }
};
