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
        Schema::create('festival_timeline_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_timeline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_schedule_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('festival_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_reference')->nullable();
            $table->string('label');
            $table->string('type');
            $table->text('notes')->nullable();
            $table->unsignedInteger('duration_seconds');
            $table->timestamp('planned_starts_at');
            $table->timestamp('planned_ends_at');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['festival_timeline_id', 'sort_order', 'id'], 'festival_timeline_item_order_idx');
            $table->index(['festival_timeline_id', 'is_enabled', 'planned_starts_at'], 'festival_timeline_item_start_idx');
            $table->index(['festival_timeline_id', 'is_enabled', 'planned_ends_at'], 'festival_timeline_item_end_idx');
        });

        Schema::table('festival_timelines', function (Blueprint $table) {
            $table->foreign('active_item_id')->references('id')->on('festival_timeline_items')->nullOnDelete();
            $table->foreign('last_finished_item_id')->references('id')->on('festival_timeline_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_timelines', function (Blueprint $table) {
            $table->dropForeign(['active_item_id']);
            $table->dropForeign(['last_finished_item_id']);
        });

        Schema::dropIfExists('festival_timeline_items');
    }
};
