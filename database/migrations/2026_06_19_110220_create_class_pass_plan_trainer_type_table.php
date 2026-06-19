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
        Schema::create('class_pass_plan_trainer_type', function (Blueprint $table) {
            $table->foreignId('class_pass_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['class_pass_plan_id', 'trainer_type_id']);
            $table->index('trainer_type_id', 'class_pass_plan_trainer_type_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_pass_plan_trainer_type');
    }
};
