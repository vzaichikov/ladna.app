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
        Schema::create('class_pass_plan_activity_direction', function (Blueprint $table) {
            $table->foreignId('class_pass_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_direction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['class_pass_plan_id', 'activity_direction_id'], 'class_pass_plan_direction_primary');
            $table->index('activity_direction_id', 'class_pass_plan_direction_direction_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_pass_plan_activity_direction');
    }
};
