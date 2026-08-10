<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('festival_score_sheets')
            ->where('status', 'locked')
            ->update(['status' => 'submitted']);

        $submissionIdsToKeep = DB::table('festival_submissions')
            ->selectRaw('MAX(id) as id')
            ->groupBy('festival_entry_requirement_id')
            ->pluck('id');

        if ($submissionIdsToKeep->isNotEmpty()) {
            DB::table('festival_submissions')->whereNotIn('id', $submissionIdsToKeep)->delete();
        }

        if (Schema::hasColumn('festival_categories', 'version')) {
            Schema::table('festival_categories', function (Blueprint $table): void {
                $table->dropUnique('festival_categories_festival_edition_id_code_version_unique');
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
                $table->dropColumn(['version', 'locked_at']);
            });
        }

        if (Schema::hasColumn('festival_workflows', 'version')) {
            Schema::table('festival_workflows', function (Blueprint $table): void {
                $table->dropUnique('festival_workflow_version_unique');
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
                $table->dropColumn(['version', 'locked_at']);
            });
        }

        if (Schema::hasColumn('festival_workflow_steps', 'locked_at')) {
            Schema::table('festival_workflow_steps', function (Blueprint $table): void {
                $table->dropColumn('locked_at');
            });
        }

        if (Schema::hasColumn('festival_requirement_definitions', 'version')) {
            Schema::table('festival_requirement_definitions', function (Blueprint $table): void {
                $table->dropColumn(['version', 'locked_at']);
            });
        }

        if (Schema::hasColumn('festival_charge_definitions', 'version')) {
            Schema::table('festival_charge_definitions', function (Blueprint $table): void {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
                $table->dropColumn(['version', 'locked_at']);
            });
        }

        if (Schema::hasColumn('festival_rubrics', 'version')) {
            Schema::table('festival_rubrics', function (Blueprint $table): void {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
                $table->dropColumn(['version', 'locked_at']);
            });
        }

        if (! Schema::hasColumn('festival_classification_axes', 'is_active')) {
            Schema::table('festival_classification_axes', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('is_required');
            });
        }

        if (! Schema::hasColumn('festival_documents', 'is_active')) {
            Schema::table('festival_documents', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('size_bytes');
            });
        }

        if (! Schema::hasColumn('festival_media', 'is_active')) {
            Schema::table('festival_media', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('is_cover');
            });
        }

        if (Schema::hasColumn('festival_submissions', 'version')) {
            Schema::table('festival_submissions', function (Blueprint $table): void {
                $table->index('festival_entry_requirement_id', 'festival_submissions_requirement_index');
                $table->dropUnique('festival_submission_version_unique');
                $table->unique('festival_entry_requirement_id', 'festival_submission_requirement_unique');
                $table->dropColumn('version');
            });
        }

        if (Schema::hasColumn('festival_score_sheets', 'lock_version')) {
            Schema::table('festival_score_sheets', function (Blueprint $table): void {
                $table->dropForeign(['unlocked_by']);
                $table->dropColumn(['lock_version', 'locked_at', 'unlocked_by', 'unlock_reason']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_score_sheets', function (Blueprint $table): void {
            $table->unsignedInteger('lock_version')->default(1)->after('total_score');
            $table->timestamp('locked_at')->nullable()->after('submitted_at');
            $table->foreignId('unlocked_by')->nullable()->after('locked_at')->constrained('users')->nullOnDelete();
            $table->text('unlock_reason')->nullable()->after('unlocked_by');
        });

        Schema::table('festival_submissions', function (Blueprint $table): void {
            $table->dropUnique('festival_submission_requirement_unique');
            $table->unsignedInteger('version')->default(1)->after('festival_portal_user_id');
            $table->unique(['festival_entry_requirement_id', 'version'], 'festival_submission_version_unique');
        });

        Schema::table('festival_media', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::table('festival_documents', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::table('festival_classification_axes', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });

        Schema::table('festival_rubrics', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('name');
            $table->timestamp('locked_at')->nullable()->after('is_active');
            $table->dropColumn('sort_order');
        });

        Schema::table('festival_charge_definitions', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('is_active');
            $table->timestamp('locked_at')->nullable()->after('version');
            $table->dropColumn('sort_order');
        });

        Schema::table('festival_requirement_definitions', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('sort_order');
            $table->timestamp('locked_at')->nullable()->after('version');
        });

        Schema::table('festival_workflow_steps', function (Blueprint $table): void {
            $table->timestamp('locked_at')->nullable()->after('is_active');
        });

        Schema::table('festival_workflows', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('name');
            $table->timestamp('locked_at')->nullable()->after('is_active');
            $table->dropColumn('sort_order');
            $table->unique(['festival_edition_id', 'name', 'version'], 'festival_workflow_version_unique');
        });

        Schema::table('festival_categories', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('rule_snapshot');
            $table->timestamp('locked_at')->nullable()->after('is_active');
            $table->dropColumn('sort_order');
            $table->unique(['festival_edition_id', 'code', 'version']);
        });
    }
};
