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
        Schema::table('festival_rubric_sections', function (Blueprint $table): void {
            $table->string('contribution')->default('award')->after('weight');
        });

        Schema::create('festival_judge_assignment_rubric_section', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_judge_assignment_id');
            $table->foreignId('festival_rubric_section_id');
            $table->timestamps();

            $table->foreign('festival_judge_assignment_id', 'festival_judge_section_assignment_fk')
                ->references('id')
                ->on('festival_judge_assignments')
                ->cascadeOnDelete();
            $table->foreign('festival_rubric_section_id', 'festival_judge_rubric_section_fk')
                ->references('id')
                ->on('festival_rubric_sections')
                ->restrictOnDelete();
            $table->unique(
                ['festival_judge_assignment_id', 'festival_rubric_section_id'],
                'festival_judge_rubric_section_unique',
            );
        });

        Schema::table('festival_results', function (Blueprint $table): void {
            $table->json('publication_details')->nullable()->after('medal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_results', function (Blueprint $table): void {
            $table->dropColumn('publication_details');
        });

        Schema::dropIfExists('festival_judge_assignment_rubric_section');

        Schema::table('festival_rubric_sections', function (Blueprint $table): void {
            $table->dropColumn('contribution');
        });
    }
};
