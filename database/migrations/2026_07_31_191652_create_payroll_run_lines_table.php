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
        Schema::create('payroll_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->restrictOnDelete();
            $table->json('amounts');
            $table->json('model_names')->nullable();
            $table->json('entries');
            $table->boolean('incomplete')->default(false);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'trainer_id']);
            $table->index(['account_id', 'trainer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_run_lines');
    }
};
