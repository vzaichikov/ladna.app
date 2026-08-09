<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('festival_schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_stage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('performance');
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reschedule_reason')->nullable();
            $table->timestamps();
            $table->index(['festival_stage_id', 'starts_at', 'ends_at'], 'festival_slots_stage_time_idx');
            $table->index(['festival_edition_id', 'type', 'starts_at'], 'festival_slots_edition_type_idx');
        });

        Schema::create('festival_judge_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('festival_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name');
            $table->boolean('is_head_judge')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['festival_edition_id', 'is_active']);
        });

        Schema::create('festival_category_judge_assignment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_judge_assignment_id')->constrained(indexName: 'festival_judge_category_assignment_fk')->cascadeOnDelete();
            $table->foreignId('festival_category_id')->constrained()->cascadeOnDelete();
            $table->unique(['festival_judge_assignment_id', 'festival_category_id'], 'festival_judge_category_unique');
        });

        Schema::create('festival_rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->index(['festival_edition_id', 'festival_category_id', 'is_active'], 'festival_rubrics_scope_idx');
        });

        Schema::create('festival_rubric_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_rubric_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('weight', 8, 4)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('festival_rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_rubric_section_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('max_score', 10, 2);
            $table->decimal('weight', 8, 4)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('festival_score_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_judge_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_rubric_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->text('comments')->nullable();
            $table->decimal('total_score', 12, 4)->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('unlock_reason')->nullable();
            $table->timestamps();
            $table->unique(['festival_entry_id', 'festival_judge_assignment_id'], 'festival_entry_judge_sheet_unique');
        });

        Schema::create('festival_criterion_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_score_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_rubric_criterion_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 10, 2);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['festival_score_sheet_id', 'festival_rubric_criterion_id'], 'festival_sheet_criterion_unique');
        });

        Schema::create('festival_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_score_sheet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind')->default('deduction');
            $table->decimal('points', 10, 2);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('festival_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_score', 12, 4);
            $table->unsignedInteger('rank')->nullable();
            $table->string('medal')->nullable();
            $table->json('details_snapshot');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->unique('festival_entry_id');
            $table->index(['festival_edition_id', 'published_at', 'rank'], 'festival_results_public_idx');
        });

        Schema::create('festival_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_optional')->default(true);
            $table->timestamps();
            $table->unique(['account_id', 'type']);
        });

        Schema::create('festival_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->constrained(indexName: 'festival_notification_pref_portal_fk')->cascadeOnDelete();
            $table->string('type');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['festival_portal_user_id', 'type'], 'festival_notification_pref_unique');
        });

        Schema::create('festival_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->longText('body');
            $table->json('audience')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('festival_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('festival_edition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('festival_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('channel')->default('email');
            $table->string('status')->default('pending')->index();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('dedupe_key')->unique();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status', 'available_at']);
        });

        Schema::create('festival_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_portal_user_id')->nullable()->constrained('festival_portal_users')->nullOnDelete();
            $table->string('action')->index();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['account_id', 'subject_type', 'subject_id'], 'festival_activity_subject_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_activity_logs');
        Schema::dropIfExists('festival_notifications');
        Schema::dropIfExists('festival_announcements');
        Schema::dropIfExists('festival_notification_preferences');
        Schema::dropIfExists('festival_notification_settings');
        Schema::dropIfExists('festival_results');
        Schema::dropIfExists('festival_penalties');
        Schema::dropIfExists('festival_criterion_scores');
        Schema::dropIfExists('festival_score_sheets');
        Schema::dropIfExists('festival_rubric_criteria');
        Schema::dropIfExists('festival_rubric_sections');
        Schema::dropIfExists('festival_rubrics');
        Schema::dropIfExists('festival_category_judge_assignment');
        Schema::dropIfExists('festival_judge_assignments');
        Schema::dropIfExists('festival_schedule_slots');
    }
};
