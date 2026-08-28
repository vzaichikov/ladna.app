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
        Schema::create('festival_entry_requirement_helper', function (Blueprint $table) {
            $table->id();
            $table->foreignId('festival_entry_requirement_id');
            $table->foreignId('festival_participant_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(
                ['festival_entry_requirement_id', 'festival_participant_id'],
                'festival_req_helper_unique',
            );
            $table->index('festival_participant_id', 'festival_req_helper_participant_idx');
            $table->foreign('festival_entry_requirement_id', 'festival_req_helper_requirement_fk')
                ->references('id')
                ->on('festival_entry_requirements')
                ->cascadeOnDelete();
            $table->foreign('festival_participant_id', 'festival_req_helper_participant_fk')
                ->references('id')
                ->on('festival_participants')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_entry_requirement_helper');
    }
};
