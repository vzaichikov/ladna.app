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
        Schema::create('festival_battle_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_category_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedInteger('position');
            $table->foreignId('entry_a_id')->nullable();
            $table->foreignId('entry_b_id')->nullable();
            $table->foreignId('next_match_id')->nullable();
            $table->char('next_position', 1)->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedBigInteger('audience_votes_a')->nullable();
            $table->unsignedBigInteger('audience_votes_b')->nullable();
            $table->unsignedInteger('judge_votes_a')->nullable();
            $table->unsignedInteger('judge_votes_b')->nullable();
            $table->decimal('jury_percentage_a', 7, 4)->nullable();
            $table->decimal('jury_percentage_b', 7, 4)->nullable();
            $table->decimal('audience_percentage_a', 7, 4)->nullable();
            $table->decimal('audience_percentage_b', 7, 4)->nullable();
            $table->decimal('combined_percentage_a', 7, 4)->nullable();
            $table->decimal('combined_percentage_b', 7, 4)->nullable();
            $table->foreignId('winner_entry_id')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('tie_break_reason')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->foreign('entry_a_id', 'festival_battle_matches_entry_a_fk')->references('id')->on('festival_entries')->restrictOnDelete();
            $table->foreign('entry_b_id', 'festival_battle_matches_entry_b_fk')->references('id')->on('festival_entries')->restrictOnDelete();
            $table->foreign('winner_entry_id', 'festival_battle_matches_winner_fk')->references('id')->on('festival_entries')->restrictOnDelete();
            $table->foreign('next_match_id', 'festival_battle_matches_next_fk')->references('id')->on('festival_battle_matches')->nullOnDelete();
            $table->unique(['festival_category_id', 'round', 'position'], 'festival_battle_category_round_position_unique');
            $table->index(['festival_edition_id', 'festival_category_id', 'status'], 'festival_battle_matches_scope_status_idx');
        });

        Schema::create('festival_battle_judge_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_battle_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_judge_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('selected_entry_id')->constrained('festival_entries')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['festival_battle_match_id', 'festival_judge_assignment_id'], 'festival_battle_match_judge_unique');
            $table->index(['festival_judge_assignment_id', 'festival_battle_match_id'], 'festival_battle_judge_assignment_match_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_battle_judge_votes');
        Schema::dropIfExists('festival_battle_matches');
    }
};
