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
        Schema::create('trainer_salary_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_model_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_from');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(
                ['trainer_id', 'effective_from', 'superseded_at'],
                'trainer_salary_assignments_effective_index',
            );
            $table->index(['account_id', 'salary_model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainer_salary_assignments');
    }
};
