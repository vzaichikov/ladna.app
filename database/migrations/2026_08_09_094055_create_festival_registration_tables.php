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
        Schema::create('festival_classification_axes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('kind')->default('custom');
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_edition_id', 'code']);
        });

        Schema::create('festival_classification_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_classification_axis_id')->constrained(indexName: 'festival_axis_option_axis_fk')->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_classification_axis_id', 'code'], 'festival_axis_option_code_unique');
        });

        Schema::create('festival_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('workflow')->default('review');
            $table->unsignedSmallInteger('min_members')->default(1);
            $table->unsignedSmallInteger('max_members')->default(1);
            $table->unsignedSmallInteger('min_age')->nullable();
            $table->unsignedSmallInteger('max_age')->nullable();
            $table->unsignedInteger('min_duration_seconds')->nullable();
            $table->unsignedInteger('max_duration_seconds')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->json('rule_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['festival_edition_id', 'code', 'version']);
            $table->index(['festival_edition_id', 'is_active']);
        });

        Schema::create('festival_category_option', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_classification_option_id')->constrained(indexName: 'festival_category_option_value_fk')->cascadeOnDelete();
            $table->unique(['festival_category_id', 'festival_classification_option_id'], 'festival_category_option_unique');
        });

        Schema::create('festival_requirement_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->text('instructions')->nullable();
            $table->string('stage')->default('final');
            $table->timestamp('due_at')->nullable();
            $table->json('allowed_extensions')->nullable();
            $table->json('allowed_mime_types')->nullable();
            $table->unsignedInteger('max_size_kb')->default(20480);
            $table->unsignedInteger('min_duration_seconds')->nullable();
            $table->unsignedInteger('max_duration_seconds')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->index(['festival_edition_id', 'festival_category_id', 'stage'], 'festival_requirements_scope_idx');
        });

        Schema::create('festival_charge_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->timestamp('due_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->index(['festival_edition_id', 'festival_category_id', 'kind'], 'festival_charge_definitions_scope_idx');
        });

        Schema::create('festival_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_category_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('performer_name');
            $table->string('act_title')->nullable();
            $table->text('act_description')->nullable();
            $table->string('coach_name_snapshot')->nullable();
            $table->string('studio_name_snapshot')->nullable();
            $table->text('comments')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('qualification_status')->default('not_required')->index();
            $table->json('category_snapshot')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['festival_edition_id', 'status', 'created_at']);
            $table->index(['festival_portal_user_id', 'festival_edition_id'], 'festival_entries_portal_edition_idx');
        });

        Schema::create('festival_entry_participant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_participant_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('age_snapshot');
            $table->string('name_snapshot');
            $table->unique(['festival_entry_id', 'festival_participant_id'], 'festival_entry_participant_unique');
        });

        Schema::create('festival_entry_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_requirement_definition_id')->constrained(indexName: 'festival_entry_requirement_def_fk')->restrictOnDelete();
            $table->string('status')->default('missing')->index();
            $table->json('definition_snapshot');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['festival_entry_id', 'festival_requirement_definition_id'], 'festival_entry_requirement_unique');
        });

        Schema::create('festival_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status')->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['festival_entry_requirement_id', 'version'], 'festival_submission_version_unique');
        });

        Schema::create('festival_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_charge_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('kind');
            $table->string('name');
            $table->string('status')->default('pending')->index();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->json('definition_snapshot')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['festival_entry_id', 'status']);
        });

        Schema::create('festival_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_charge_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('order_id')->unique();
            $table->string('status')->default('pending')->index();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('gateway_invoice_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_status')->nullable();
            $table->json('gateway_checkout_payload')->nullable();
            $table->json('last_callback_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['festival_charge_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_payment_attempts');
        Schema::dropIfExists('festival_charges');
        Schema::dropIfExists('festival_submissions');
        Schema::dropIfExists('festival_entry_requirements');
        Schema::dropIfExists('festival_entry_participant');
        Schema::dropIfExists('festival_entries');
        Schema::dropIfExists('festival_charge_definitions');
        Schema::dropIfExists('festival_requirement_definitions');
        Schema::dropIfExists('festival_category_option');
        Schema::dropIfExists('festival_categories');
        Schema::dropIfExists('festival_classification_options');
        Schema::dropIfExists('festival_classification_axes');
    }
};
