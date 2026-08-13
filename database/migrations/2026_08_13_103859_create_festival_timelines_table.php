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
        Schema::create('festival_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_stage_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_item_id')->nullable();
            $table->unsignedBigInteger('last_finished_item_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_transition_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['festival_edition_id', 'festival_stage_id'], 'festival_timeline_scene_unique');
            $table->index(['account_id', 'festival_edition_id'], 'festival_timeline_edition_idx');
            $table->index(['started_at', 'paused_at', 'next_transition_at'], 'festival_timeline_transition_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_timelines');
    }
};
