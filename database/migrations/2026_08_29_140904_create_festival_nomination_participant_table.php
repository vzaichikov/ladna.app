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
        Schema::create('festival_nomination_participant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_nomination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_participant_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['festival_nomination_id', 'festival_participant_id'], 'festival_nomination_participant_unique');
            $table->index(['account_id', 'festival_participant_id'], 'festival_nomination_participant_account_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_nomination_participant');
    }
};
