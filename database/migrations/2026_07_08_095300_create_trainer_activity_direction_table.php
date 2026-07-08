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
        Schema::create('trainer_activity_direction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_direction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['trainer_id', 'activity_direction_id'], 'trainer_activity_direction_unique');
            $table->index(['account_id', 'activity_direction_id'], 'trainer_activity_direction_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainer_activity_direction');
    }
};
