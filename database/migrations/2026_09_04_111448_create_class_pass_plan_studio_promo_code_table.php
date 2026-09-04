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
        Schema::create('class_pass_plan_studio_promo_code', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_pass_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('studio_promo_code_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['class_pass_plan_id', 'studio_promo_code_id'], 'class_pass_plan_studio_promo_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_pass_plan_studio_promo_code');
    }
};
